<?php

namespace App\Services\Integrations\Contracts;

use App\Models\User;
use Illuminate\Http\Client\Response;

interface ProviderClient
{
    public function get(string $path, array $query = [], ?User $user = null, ?int $relatedTransferId = null): Response;

    public function post(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response;

    public function put(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response;

    public function delete(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response;
}
