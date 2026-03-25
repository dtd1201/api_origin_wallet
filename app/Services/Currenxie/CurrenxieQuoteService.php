<?php

namespace App\Services\Currenxie;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CurrenxieQuoteService implements QuoteProvider
{
    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'currenxie',
            headers: $this->requestHeaders(),
        );

        $response = $client->post(
            path: (string) config('services.currenxie.quote_endpoint'),
            payload: [
                'user_reference' => (string) $user->id,
                ...$payload,
            ],
            user: $user,
        );

        $responseData = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Currenxie quote creation failed.');
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => $responseData['quote_ref'] ?? 'QTE-'.Str::upper(Str::random(10)),
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => $payload['source_amount'],
            'target_amount' => $responseData['target_amount'] ?? $payload['target_amount'] ?? 0,
            'mid_rate' => $responseData['mid_rate'] ?? null,
            'net_rate' => $responseData['net_rate'] ?? $responseData['fx_rate'] ?? null,
            'fee_amount' => $responseData['fee_amount'] ?? 0,
            'expires_at' => $responseData['expires_at'] ?? now()->addMinutes(15),
            'raw_data' => $responseData,
        ]));
    }

    private function requestHeaders(): array
    {
        if (strtolower((string) config('services.currenxie.auth.mode', 'static_headers')) !== 'static_headers') {
            return [];
        }

        return [
            'X-API-KEY' => (string) config('services.currenxie.api_key'),
            'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
        ];
    }
}
