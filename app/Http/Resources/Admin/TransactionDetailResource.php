<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class TransactionDetailResource extends TransactionListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'reference_text' => $this->reference_text,
        ];
    }
}
