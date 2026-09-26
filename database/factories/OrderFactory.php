<?php

namespace Database\Factories;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\Status;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shippingFee = $this->faker->numberBetween(0, 200);
        $taxAmount = $this->faker->numberBetween(10, 300);
        $subtotal = $this->faker->numberBetween(500, 5000);

        return [
            'member_id' => Member::factory(),
            'number' => $this->faker->unique()->numerify('ORD############'),
            'idempotency_key' => $this->faker->unique()->uuid(),
            'total_amount' => $subtotal + $taxAmount + $shippingFee,
            'tax_amount' => $taxAmount,
            'shipping_fee' => $shippingFee,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => $this->faker->name(),
            'recipient_phone' => $this->faker->numerify('09########'),
            'recipient_zip_code' => $this->faker->postcode(),
            'recipient_address' => $this->faker->address(),
            'store_type' => null,
            'store_code' => null,
            'store_name' => null,
            'store_address' => null,
            'status' => Status::STOCKING->value,
            'payment_method' => $this->faker->randomElement(PaymentMethod::cases())->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ];
    }
}
