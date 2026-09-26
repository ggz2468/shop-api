<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'member_id',
        'number',
        'idempotency_key',
        'total_amount',
        'tax_amount',
        'shipping_fee',
        'shipping_method',
        'recipient_name',
        'recipient_phone',
        'recipient_zip_code',
        'recipient_address',
        'store_type',
        'store_code',
        'store_name',
        'store_address',
        'status',
        'payment_method',
        'payment_status',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'total_amount' => 'integer',
        'tax_amount' => 'integer',
        'shipping_fee' => 'integer',
        'shipping_method' => 'integer',
        'status' => 'integer',
        'payment_method' => 'integer',
        'payment_status' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function orderDetails(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
