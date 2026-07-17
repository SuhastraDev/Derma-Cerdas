<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedFlag extends Model
{
    protected $fillable = [
        'code',
        'question',
        'action_message',
        'severity',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
