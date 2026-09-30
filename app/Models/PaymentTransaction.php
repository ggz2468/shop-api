<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'provider_transaction_id',
        'merchant_trade_no',
        'amount',
        'currency',
        'status',
        'payment_method',
        'request_payload',
        'checkout_payload',
        'response_payload',
        'expires_at',
        'paid_at',
        'failed_at',
        'canceled_at',
        'refunded_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'provider' => 'integer',
        'amount' => 'integer',
        'status' => 'integer',
        'payment_method' => 'integer',
        'request_payload' => 'array',
        'checkout_payload' => 'array',
        'response_payload' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
