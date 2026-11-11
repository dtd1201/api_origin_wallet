<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\HttpReadiness;

/** Prepared status/body/headers only. Does not create or send an HTTP response. */
final readonly class ReadOnlyOptionsResponse
{
    public function __construct(private int $status, private array $body) {}

    public function status(): int
    {
        return $this->status;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Content-Type' => 'application/json'];
    }
}
