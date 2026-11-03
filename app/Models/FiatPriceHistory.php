<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiatPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'fiat_price_histories';

    protected $fillable = [
        'fiat_id',
        'price_irr',
        'source_timestamp',
    ];

    protected $casts = [
        'price_irr' => 'integer',
        'source_timestamp' => 'integer',
    ];

    public function fiat(): BelongsTo
    {
        return $this->belongsTo(Fiat::class);
    }
}
