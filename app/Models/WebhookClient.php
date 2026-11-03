<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
