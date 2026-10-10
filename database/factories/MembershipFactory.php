<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => MembershipRole::Member,
            'sensitivity_class' => 'personal',
            'provenance' => Provenance::job('MembershipFactory', ProvenanceOrigin::Manual),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MembershipRole::Owner,
        ]);
    }
}
