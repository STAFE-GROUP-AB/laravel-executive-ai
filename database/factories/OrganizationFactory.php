<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'timezone' => 'Europe/Stockholm',
            'language' => 'sv',
            'sensitivity_class' => 'internal',
            'provenance' => Provenance::job('OrganizationFactory', ProvenanceOrigin::Manual),
        ];
    }
}
