<?php

namespace App\Services\BankRates;

use App\Models\ManagedExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BankRateSyncService
{
    public function sync(?array $sourceKeys = null): array
    {
        $sources = $this->sourceConfigs($sourceKeys);
        $summary = [
            'synced_at' => now()->toISOString(),
            'upserted' => 0,
            'failed' => 0,
            'sources' => [],
        ];

        foreach ($sources as $sourceKey => $source) {
            try {
                $rates = $this->fetchRates($source);
                $upserted = $this->upsertRates($rates);
                $summary['upserted'] += $upserted;
                $summary['sources'][] = [
                    'key' => $sourceKey,
                    'code' => $source['code'],
                    'name' => $source['name'],
                    'rate_count' => count($rates),
                    'upserted' => $upserted,
                    'published_at' => $this->latestPublishedAt($rates)?->toISOString(),
                ];
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['sources'][] = [
                    'key' => $sourceKey,
                    'code' => $source['code'] ?? $sourceKey,
                    'name' => $source['name'] ?? $sourceKey,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($summary['upserted'] > 0) {
            Cache::flush();
        }

        return $summary;
    }

    private function fetchRates(array $source): array
    {
        $response = Http::timeout((int) config('services.bank_rate_sources.timeout', 20))
            ->withUserAgent('OriginWalletBankRateSync/1.0')
            ->accept($source['accept'] ?? 'application/json')
            ->get((string) $source['url']);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} returned by {$source['name']}.");
        }

        $rates = match ($source['parser']) {
            'vietcombank_xml' => $this->parseVietcombankXml($source, $response->body()),
            'techcombank_json' => $this->parseTechcombankJson($source, $response->json() ?? []),
            default => throw new RuntimeException("Unsupported bank rate parser [{$source['parser']}]."),
        };

        if ($rates === []) {
            throw new RuntimeException("No rates parsed from {$source['name']}.");
        }

        return $rates;
    }

    private function parseVietcombankXml(array $source, string $xml): array
    {
        $publishedAt = $this->parseVietcombankDate($this->readXmlText($xml, 'DateTime'));
        preg_match_all('/<Exrate\s+([^>]+?)\s*\/>/i', $xml, $matches);

        return collect($matches[1] ?? [])
            ->map(function (string $attributes, int $index) use ($publishedAt, $source): ?array {
                $row = $this->xmlAttributes($attributes);

                return $this->buildRate($source, [
                    'source_currency' => $row['CurrencyCode'] ?? null,
                    'buy_cash' => $this->parseRate($row['Buy'] ?? null),
                    'buy_transfer' => $this->parseRate($row['Transfer'] ?? null),
                    'sell_transfer' => $this->parseRate($row['Sell'] ?? null),
                    'published_at' => $publishedAt,
                    'label' => trim((string) ($row['CurrencyName'] ?? '')),
                    'display_order' => ((int) ($source['display_order'] ?? 0)) + $index,
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function parseTechcombankJson(array $source, array $payload): array
    {
        $rows = data_get($payload, 'exchangeRate.data', []);
        $bestRows = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $currency = $this->normalizeCurrency($row['sourceCurrency'] ?? null);

            if ($currency === null) {
                continue;
            }

            if (! isset($bestRows[$currency]) || $this->scoreTechcombankRow($row) > $this->scoreTechcombankRow($bestRows[$currency])) {
                $bestRows[$currency] = $row;
            }
        }

        return collect($bestRows)
            ->values()
            ->map(fn (array $row, int $index): ?array => $this->buildRate($source, [
                'source_currency' => $row['sourceCurrency'] ?? null,
                'buy_cash' => $this->parseRate($row['bidRateTM'] ?? null),
                'buy_transfer' => $this->parseRate($row['bidRateCK'] ?? null),
                'sell_cash' => $this->parseRate($row['askRateTM'] ?? null),
                'sell_transfer' => $this->parseRate($row['askRate'] ?? null),
                'published_at' => $this->parseDate($row['inputDate'] ?? null),
                'label' => (string) ($row['label'] ?? ''),
                'display_order' => ((int) ($source['display_order'] ?? 0)) + $index,
            ]))
            ->filter()
            ->values()
            ->all();
    }

    private function buildRate(array $source, array $row): ?array
    {
        $currency = $this->normalizeCurrency($row['source_currency'] ?? null);

        if ($currency === null) {
            return null;
        }

        $buyRate = $row['buy_transfer'] ?? $row['buy_cash'] ?? null;
        $sellRate = $row['sell_transfer'] ?? $row['sell_cash'] ?? null;
        $midRate = $buyRate !== null && $sellRate !== null ? round(($buyRate + $sellRate) / 2, 8) : null;

        if ($buyRate === null && $sellRate === null && $midRate === null) {
            return null;
        }

        return [
            'source_code' => strtolower((string) $source['code']),
            'source_name' => (string) $source['name'],
            'source_currency' => $currency,
            'target_currency' => 'VND',
            'buy_rate' => $buyRate,
            'sell_rate' => $sellRate,
            'mid_rate' => $midRate,
            'fee_amount' => 0,
            'status' => 'active',
            'display_order' => (int) ($row['display_order'] ?? $source['display_order'] ?? 0),
            'notes' => $this->notes($source, $row),
            'published_at' => $row['published_at'] ?? null,
        ];
    }

    private function upsertRates(array $rates): int
    {
        $audiences = $this->audiences();
        $count = 0;

        DB::transaction(function () use ($audiences, &$count, $rates): void {
            foreach ($rates as $rate) {
                foreach ($audiences as $audience) {
                    ManagedExchangeRate::query()->updateOrCreate(
                        [
                            'rate_type' => ManagedExchangeRate::TYPE_BANK,
                            'audience' => $audience,
                            'source_code' => $rate['source_code'],
                            'source_currency' => $rate['source_currency'],
                            'target_currency' => $rate['target_currency'],
                        ],
                        Arr::except($rate, ['source_code', 'source_currency', 'target_currency']),
                    );

                    $count++;
                }
            }
        });

        return $count;
    }

    private function sourceConfigs(?array $sourceKeys): array
    {
        $configured = (array) config('services.bank_rate_sources.sources', []);
        $enabled = collect($configured)
            ->filter(fn (array $source): bool => (bool) ($source['enabled'] ?? true))
            ->all();

        if ($sourceKeys !== null && $sourceKeys !== []) {
            $requested = collect($sourceKeys)
                ->map(fn ($sourceKey): string => strtolower(trim((string) $sourceKey)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($requested as $sourceKey) {
                if (! array_key_exists($sourceKey, $configured)) {
                    throw new RuntimeException("Unsupported bank rate source [{$sourceKey}].");
                }
            }

            $enabled = Arr::only($configured, $requested);
        }

        if ($enabled === []) {
            throw new RuntimeException('No bank rate sources are enabled.');
        }

        return $enabled;
    }

    private function audiences(): array
    {
        $configured = (array) config('services.bank_rate_sources.audiences', [
            ManagedExchangeRate::AUDIENCE_PUBLIC,
            ManagedExchangeRate::AUDIENCE_AUTHENTICATED,
        ]);

        return collect($configured)
            ->map(fn ($audience): string => (string) $audience)
            ->filter(fn (string $audience): bool => in_array($audience, [
                ManagedExchangeRate::AUDIENCE_PUBLIC,
                ManagedExchangeRate::AUDIENCE_AUTHENTICATED,
            ], true))
            ->unique()
            ->values()
            ->all() ?: [ManagedExchangeRate::AUDIENCE_PUBLIC];
    }

    private function scoreTechcombankRow(array $row): int
    {
        $score = 0;

        foreach (['bidRateCK', 'askRate'] as $field) {
            if ($this->parseRate($row[$field] ?? null) !== null) {
                $score += 3;
            }
        }

        foreach (['bidRateTM', 'askRateTM'] as $field) {
            if ($this->parseRate($row[$field] ?? null) !== null) {
                $score += 1;
            }
        }

        if (str_contains((string) ($row['label'] ?? ''), '50,100')) {
            $score += 2;
        }

        return $score;
    }

    private function notes(array $source, array $row): string
    {
        $parts = ['Synced from '.$source['name'].'.'];
        $label = trim((string) ($row['label'] ?? ''));

        if ($label !== '' && $label !== ($row['source_currency'] ?? '')) {
            $parts[] = 'Label: '.$label.'.';
        }

        if (isset($source['url'])) {
            $parts[] = 'Source: '.$source['url'];
        }

        return implode(' ', $parts);
    }

    private function xmlAttributes(string $value): array
    {
        preg_match_all('/([A-Za-z0-9_:-]+)="([^"]*)"/', $value, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => html_entity_decode($match[2], ENT_QUOTES | ENT_XML1)])
            ->all();
    }

    private function readXmlText(string $xml, string $tagName): ?string
    {
        preg_match('/<'.$tagName.'>(.*?)<\/'.$tagName.'>/s', $xml, $match);

        return isset($match[1]) ? html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_XML1) : null;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }

    private function parseRate(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(str_replace(',', '', (string) $value));

        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseVietcombankDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('n/j/Y g:i:s A', trim($value), 'Asia/Ho_Chi_Minh');

            return $date instanceof CarbonImmutable ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function latestPublishedAt(array $rates): ?CarbonImmutable
    {
        return collect($rates)
            ->pluck('published_at')
            ->filter()
            ->sortBy(fn (CarbonImmutable $publishedAt): int => $publishedAt->getTimestamp())
            ->last();
    }
}
