<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'fiat_id',
        'base_price_fiat',
        'profit_percent',
        'price_toman',
        'price_source_timestamp',
    ];

    protected $casts = [
        'base_price_fiat' => 'decimal:2',
        'profit_percent' => 'decimal:2',
        'price_toman' => 'integer',
        'price_source_timestamp' => 'integer',
    ];

    public function fiat(): BelongsTo
    {
        return $this->belongsTo(Fiat::class);
    }
}
