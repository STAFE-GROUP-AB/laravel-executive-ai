<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Briefs\Generation\PlaceholderBrief;
use App\Models\Brief;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;

/**
 * The state the PWA pages render from: an owner with a membership, the
 * install-time placeholder, and the §1.2 copy denylist. Brief fixtures come
 * from Tests\Support\BriefFixtures. Runs inside DatabaseTransactions.
 */
final class PwaFixtures
{
    /** packaging/fixtures/README.md "Fixture clock": every fixture except brief-stale is fresh here. */
    public const string FIXTURE_NOW = '2026-10-09T12:00:00+02:00';

    /**
     * Blueprint §1.2: no UI copy may claim AI runs on the phone or in the
     * browser. Matched case-insensitively against the rendered HTML.
     *
     * @var list<string>
     */
    public const array AI_ON_PHONE_DENYLIST = [
        'on your phone',
        'on your iphone',
        'on this phone',
        'on this device',
        'on-device ai',
        'on device ai',
        'in your browser',
        'in the browser',
        'runs on the phone',
        'runs locally in',
        'offline ai',
        'ai runs here',
    ];

    public static function owner(?Organization $organization = null): User
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->owner()->for($organization)->for($user)->create();

        return $user;
    }

    public static function organizationOf(User $user): Organization
    {
        /** @var Membership $membership */
        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();

        return $membership->organization;
    }

    public static function placeholder(Organization $organization): Brief
    {
        $brief = app(PlaceholderBrief::class)->create($organization, CarbonImmutable::parse(self::FIXTURE_NOW));

        return $brief->fresh() ?? $brief;
    }

    /** @return list<string> The denylisted phrases found in the HTML, empty when clean. */
    public static function aiOnPhoneClaims(string $html): array
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($html)));

        return array_values(array_filter(
            self::AI_ON_PHONE_DENYLIST,
            static fn (string $phrase): bool => str_contains($text, $phrase),
        ));
    }

    public static function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }
}
