<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalDocument extends Model
{
    protected $fillable = [
        'key',
        'title',
        'body',
    ];

    public static function allowedKeys(): array
    {
        return [
            'signup_terms',
            'signup_privacy',
            'virtual_card_terms',
            'virtual_card_privacy',
        ];
    }
}
