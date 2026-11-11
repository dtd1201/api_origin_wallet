<?php

namespace App\Services\Nium;

use RuntimeException;

/** Transport rejected remittance before creating or sending its HTTP request. */
final class NiumRemittanceDispatchHeldException extends RuntimeException {}
