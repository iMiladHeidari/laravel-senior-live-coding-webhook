<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fiat extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'current_price_irr',
        'last_updated_timestamp',
        'is_active',
    ];

    protected $casts = [
        'current_price_irr' => 'integer',
        'last_updated_timestamp' => 'integer',
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(FiatPriceHistory::class);
    }
}
