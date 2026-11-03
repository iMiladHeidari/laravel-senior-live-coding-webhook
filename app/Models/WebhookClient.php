<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WebhookClient extends Model
{
    use HasFactory;

    public const CACHE_TTL_SECONDS = 60;

    protected $fillable = [
        'name',
        'token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $forget = function (WebhookClient $client): void {
            Cache::forget(self::cacheKey($client->token));

            if ($client->getOriginal('token') && $client->getOriginal('token') !== $client->token) {
                Cache::forget(self::cacheKey($client->getOriginal('token')));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public static function cacheKey(string $token): string
    {
        return 'webhook-client:'.hash('sha256', $token);
    }

    /**
     * Unknown and revoked tokens resolve to null and are not cached.
     */
    public static function findActiveByToken(string $token): ?self
    {
        return Cache::remember(
            self::cacheKey($token),
            self::CACHE_TTL_SECONDS,
            fn () => self::query()->where('token', $token)->where('is_active', true)->first(),
        );
    }
}
