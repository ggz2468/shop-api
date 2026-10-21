<?php

namespace App\Http\Resources;

use App\Models\OrderDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'created_at' => $this->created_at?->toISOString(),
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'shipping_method' => $this->shipping_method,
            'item_quantity' => (int) $this->orderDetails->sum('quantity'),
            'items_subtotal' => (int) $this->orderDetails->sum('subtotal'),
            'shipping_fee' => $this->shipping_fee,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'recipient_zip_code' => $this->recipient_zip_code,
            'recipient_address' => $this->recipient_address,
            'store_type' => $this->store_type,
            'store_code' => $this->store_code,
            'store_name' => $this->store_name,
            'store_address' => $this->store_address,
            'items' => $this->orderDetails->map(fn (OrderDetail $detail): array => [
                'product_variant_id' => $detail->product_variant_id,
                'product_name' => $detail->product_name,
                'product_sku' => $detail->product_sku,
                'product_color' => $detail->product_color,
                'product_size' => $detail->product_size,
                'product_price' => $detail->product_price,
                'quantity' => $detail->quantity,
                'subtotal' => $detail->subtotal,
            ])->all(),
        ];
    }
}
