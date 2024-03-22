<?php

namespace Database\Factories\OGame;

use Illuminate\Database\Eloquent\Factories\Factory;
use OGame\Planet;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\OGame\Planet>
 */
class PlanetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Planet::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'FakePlanetName',
            'metal_mine' => 30,
            'metal_mine_percent' => 10,
            'solar_plant' => 15,
            'solar_plant_percent' => 10,
        ];
    }
}
