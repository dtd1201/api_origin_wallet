<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internal auth state is never a public resource or a mass-assignment API. */
abstract class AuthState extends Model
{
    protected $guarded = ['*'];

    public function toArray(): array
    {
        return [];
    }

    public function attributesToArray(): array
    {
        return [];
    }
}
