<?php

namespace App\Exceptions;

use RuntimeException;

/** No original exception, request data or cryptographic material is retained. */
final class AuthenticationServiceUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication service is unavailable.');
    }
}
