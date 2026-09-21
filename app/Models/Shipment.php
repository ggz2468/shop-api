<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'tracking_number',
        'status',
        'shipping_method',
        'recipient_name',
        'recipient_phone',
        'recipient_address',
        'store_code',
        'store_type',
        'store_name',
        'store_address',
        'request_payload',
        'checkout_payload',
        'response_payload',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'provider' => 'integer',
        'status' => 'integer',
        'shipping_method' => 'integer',
        'request_payload' => 'array',
        'checkout_payload' => 'array',
        'response_payload' => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
