<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppreciationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppreciationEvent>
 */
class AppreciationEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Upacara Senin',
            'held_on' => now()->next('Monday')->format('Y-m-d'),
            // Start time (D-123), stored as 'HH:MM:00'. null = an event made before events had a time.
            'held_time' => '07:00:00',
        ];
    }
}
