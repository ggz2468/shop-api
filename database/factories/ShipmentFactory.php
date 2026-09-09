<?php

namespace Database\Factories;

use App\Enums\Shipment\Provider;
use App\Enums\Shipment\ShippingMethod;
use App\Enums\Shipment\Status;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shipment>
 */
class ShipmentFactory extends Factory
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
            'tracking_number' => $this->faker->unique()->numerify('TRK############'),
            'status' => Status::PENDING->value,
            'shipping_method' => $this->faker->randomElement(ShippingMethod::cases())->value,
            'recipient_name' => $this->faker->name(),
            'recipient_phone' => $this->faker->regexify('09[0-9]{8}'),
            'recipient_address' => $this->faker->address(),
            'store_code' => null,
            'store_type' => null,
            'store_name' => null,
            'store_address' => null,
            'request_payload' => [
                'tracking_number' => $this->faker->unique()->numerify('REQ############'),
            ],
            'response_payload' => null,
            'shipped_at' => null,
            'delivered_at' => null,
        ];
    }
}
