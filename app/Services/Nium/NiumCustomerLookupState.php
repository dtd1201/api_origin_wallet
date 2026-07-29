<?php

namespace App\Services\Nium;

enum NiumCustomerLookupState: string
{
    case Existing = 'EXISTING';
    case Absent = 'ABSENT';
    case Ambiguous = 'AMBIGUOUS';
    case Failed = 'FAILED';
}
