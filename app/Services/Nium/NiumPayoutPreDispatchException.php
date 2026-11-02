<?php

namespace App\Services\Nium;

use RuntimeException;

/** The final local check prevented dispatch; no remittance request was sent. */
final class NiumPayoutPreDispatchException extends RuntimeException {}
