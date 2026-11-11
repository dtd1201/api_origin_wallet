<?php

namespace App\Services\Nium;

/** Keep a shared drain lock alive until the protected operation has returned. */
final class NiumSgdAdmissionLease
{
    public function __construct(private mixed $handle) {}

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
