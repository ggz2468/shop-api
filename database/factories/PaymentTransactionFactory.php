<?php

namespace Database\Factories;

use App\Enums\PaymentTransaction\PaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => $this->faker->randomElement(Provider::cases())->value,
            'provider_transaction_id' => $this->faker->unique()->numerify('TX############'),
            'merchant_trade_no' => $this->faker->unique()->numerify('MTN############'),
            'amount' => $this->faker->numberBetween(500, 5000),
            'currency' => 'TWD',
            'status' => Status::PENDING->value,
            'payment_method' => $this->faker->randomElement(PaymentMethod::cases())->value,
            'request_payload' => [
                'merchant_trade_no' => $this->faker->unique()->numerify('REQ############'),
            ],
            'checkout_payload' => null,
            'response_payload' => null,
            'paid_at' => null,
            'failed_at' => null,
            'refunded_at' => null,
        ];
    }
}
