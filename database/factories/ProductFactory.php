<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\ProductSpec;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Available name definitions used by seeding and factory generation.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        $imageDirectory = public_path('storage/images/products');
        $productNames = array_map(fn ($imagePath) => basename($imagePath, '.gif'), explode(PHP_EOL, trim(shell_exec('ls ' . $imageDirectory . '/*.gif | grep -v "small"'))));
        return $productNames;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $productName = $this->faker->randomElement(self::names());
        return [
            'product_spec_id' => ProductSpec::factory(),
            'name' => $productName,
            'price' => $this->faker->numberBetween(100, 1000),
            'description' => '這是 ' . $productName . ' 的描述。',
            'created_at' => now(),
            'updated_at' => now()
        ];
    }
}
