<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'dedup_key',
        'status',
        'attempts',
        'available_at',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
