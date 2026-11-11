<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\DynamicTransferOptionsResponsePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uninstalled outer boundary; no registration, priority or termination hooks. */
final class ProtectDynamicTransferOptionsResponses
{
    public function __construct(
        private readonly DynamicTransferOptionsResponsePolicy $policy = new DynamicTransferOptionsResponsePolicy,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->matches($request)) {
            return $next($request);
        }

        // Laravel's real pipeline reports/renders exceptions. A separate scoped
        // final-response callback must pass its Throwable to the same policy.
        // Do not swallow, duplicate or globally suppress exception reporting.
        return $this->policy->normalize($request, $next($request));
    }
}
