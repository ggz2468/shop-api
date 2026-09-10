<?php

namespace App\Models;

use App\Enums\ShipmentStoreMapRequest\StoreType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentStoreMapRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'provider',
        'store_type',
        'merchant_trade_no',
        'selection_token',
        'request_payload',
        'checkout_payload',
        'response_payload',
        'selected_store_code',
        'selected_store_name',
        'selected_store_address',
        'expires_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'provider' => 'integer',
        'store_type' => StoreType::class,
        'request_payload' => 'array',
        'checkout_payload' => 'array',
        'response_payload' => 'array',
        'expires_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
