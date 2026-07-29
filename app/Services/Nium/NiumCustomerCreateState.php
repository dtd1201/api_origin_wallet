<?php

namespace App\Services\Nium;

enum NiumCustomerCreateState: string
{
    case Created = 'CREATED';
    case Duplicate = 'DUPLICATE';
    case InvalidResponse = 'INVALID_RESPONSE';
    case Failed = 'FAILED';
}
