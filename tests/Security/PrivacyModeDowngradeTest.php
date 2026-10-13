<?php

declare(strict_types=1);

use App\AI\Routing\PrivacyMode;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Security\Authorization\Devices\LoginMethod;
use App\Security\Authorization\Reauthentication\CriticalAction;
use App\Security\Authorization\Reauthentication\ReauthenticationRequired;
use App\Security\Authorization\Reauthentication\ReauthProof;
use App\Security\Privacy\PrivacyModeChangeRefused;
use App\Security\Privacy\PrivacyModeStore;
use App\Security\Privacy\PrivacyPolicyState;
use App\Support\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\OwnerAccount;

use function Pest\Laravel\actingAs;

/*
 * Threat model **T-68** and OPEN-QUESTIONS **A18**: the privacy mode turned
 * down, or `LOCAL_ONLY_ALLOW_LAN` turned on, without the owner noticing — "and
 * the UI may still suggest otherwise".
 *
 * S2-03 landed the storage. This file holds the rule, as four claims:
 *
 * - the mode lives in `privacy_policies`, append-only, one live row per
 *   workspace, and the stored row is what every surface reports;
 * - only the workspace owner may change it;
 * - a change that **widens** where model traffic may go needs the S2-05
 *   re-authentication *and* a UI re-confirmation — while tightening needs
 *   neither, because a control that makes it laborious to become stricter is a
 *   control that gets left loose;
 * - every change writes one named audit event whose id is on the policy row.
 */

uses(DatabaseTransactions::class);

function privacyOwner(): User
{
    OwnerAccount::clear();

    return OwnerAccount::create();
}

/** A user with an account but no owner membership. */
function privacyNonOwner(): User
{
    $user = User::factory()->create(['email' => 'member@example.test']);
    $organization = Organization::query()->orderBy('created_at')->first() ?? Organization::factory()->create();

    Membership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    return $user;
}

function privacyProof(?CriticalAction $action = null, ?CarbonImmutable $expiresAt = null): ReauthProof
{
    $now = CarbonImmutable::now('UTC');

    return new ReauthProof(
        action: $action ?? CriticalAction::ChangePrivacyMode,
        nonce: 'nonce-for-the-test',
        method: LoginMethod::Passkey,
        verifiedAt: $now,
        expiresAt: $expiresAt ?? $now->addSeconds(120),
    );
}

function privacyStore(): PrivacyModeStore
{
    return app(PrivacyModeStore::class);
}

/*
 * ----------------------------------------------------- where the mode lives
 */

it('reads the stored policy rather than the configured seed', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    // The config value is the install-time seed. Once a row exists, the row wins
    // — ADR-001 D4 removed the `.env`, so a stored row is the only place a
    // change can be attributed to someone.
    config(['lea.privacy_mode' => 'LOCAL_ONLY']);

    privacyStore()->change(
        actor: $owner,
        mode: PrivacyMode::Custom,
        reason: 'the owner accepted the provider terms',
        proof: privacyProof(),
        confirmed: true,
        organizationId: $organizationId,
    );

    expect(privacyStore()->active($organizationId)->mode)->toBe(PrivacyMode::Custom)
        ->and(privacyStore()->activeMode($organizationId))->toBe(PrivacyMode::Custom);
});

it('falls back to the configured seed when nothing has been stored, and falls closed when it cannot be parsed', function (): void {
    DB::table(PrivacyModeStore::TABLE)->delete();

    config(['lea.privacy_mode' => 'CUSTOM']);
    expect(privacyStore()->active()->mode)->toBe(PrivacyMode::Custom)
        ->and(privacyStore()->active()->source)->toBe(PrivacyPolicyState::SOURCE_FALLBACK);

    config(['lea.privacy_mode' => 'a mode from a newer build']);
    expect(privacyStore()->active()->mode)->toBe(PrivacyMode::LocalOnly, 'a privacy setting nobody can parse must not widen anything');
});

it('keeps the table append-only: the superseded row stays and only one policy is live', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    privacyStore()->seedInstallDefault($organizationId);
    privacyStore()->change($owner, PrivacyMode::EuRestricted, proof: privacyProof(), confirmed: true, organizationId: $organizationId);
    privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(), confirmed: true, organizationId: $organizationId);
    privacyStore()->change($owner, PrivacyMode::LocalOnly, organizationId: $organizationId);

    $rows = DB::table(PrivacyModeStore::TABLE)->where('organization_id', $organizationId)->orderBy('effective_from')->orderBy('id')->get();

    expect($rows)->toHaveCount(4, 'every change is a row; nothing is updated in place')
        ->and($rows->whereNull('superseded_at'))->toHaveCount(1, 'exactly one live policy per workspace')
        ->and($rows->last()->mode)->toBe('LOCAL_ONLY')
        ->and($rows->first()->source)->toBe(PrivacyPolicyState::SOURCE_INSTALL_DEFAULT)
        ->and($rows->first()->changed_by_user_id)->toBeNull('the install default has no user, and says so through its source');
});

it('seeds the strictest mode at install time and is idempotent', function (): void {
    $organizationId = (string) Organization::factory()->create()->id;

    expect(privacyStore()->seedInstallDefault($organizationId)->mode)->toBe(PrivacyMode::LocalOnly)
        ->and(privacyStore()->seedInstallDefault($organizationId)->mode)->toBe(PrivacyMode::LocalOnly)
        ->and(DB::table(PrivacyModeStore::TABLE)->where('organization_id', $organizationId)->count())->toBe(1);
});

/*
 * -------------------------------------------------------- who may change it
 */

it('refuses a change by anyone but the workspace owner', function (): void {
    privacyOwner();
    $member = privacyNonOwner();

    try {
        privacyStore()->change($member, PrivacyMode::Custom, proof: privacyProof(), confirmed: true);
        Assert::fail('a non-owner must not be able to change the privacy mode');
    } catch (PrivacyModeChangeRefused $refused) {
        expect($refused->reason)->toBe(PrivacyModeChangeRefused::NOT_OWNER);
    }
});

/*
 * --------------------------------- a widening change needs re-auth and confirmation
 */

it('names a widening change by what it widens, in both directions and for the LAN toggle', function (): void {
    $store = privacyStore();
    $localOnly = new PrivacyPolicyState(PrivacyMode::LocalOnly);

    expect($store->widens($localOnly, PrivacyMode::EuRestricted, false))->toBeTrue()
        ->and($store->widens($localOnly, PrivacyMode::Custom, false))->toBeTrue()
        ->and($store->widens($localOnly, PrivacyMode::LocalOnly, true))->toBeTrue('the LAN opt-in is a widening change too')
        ->and($store->widens($localOnly, PrivacyMode::LocalOnly, false))->toBeFalse()
        ->and($store->widens(new PrivacyPolicyState(PrivacyMode::Custom), PrivacyMode::LocalOnly, false))->toBeFalse('tightening is not a downgrade')
        ->and($store->widens(new PrivacyPolicyState(PrivacyMode::LocalOnly, allowLan: true), PrivacyMode::LocalOnly, true))->toBeFalse('leaving it on is not turning it on');
});

it('refuses a widening change with no re-authentication proof', function (): void {
    $owner = privacyOwner();

    try {
        privacyStore()->change($owner, PrivacyMode::Custom, confirmed: true);
        Assert::fail('a widening change must need fresh proof of presence');
    } catch (ReauthenticationRequired $required) {
        expect($required->action)->toBe(CriticalAction::ChangePrivacyMode)
            ->and($required->reason)->toBe(ReauthenticationRequired::MISSING);
    }
});

it('refuses a proof for another action, and an expired one', function (): void {
    $owner = privacyOwner();

    expect(fn (): mixed => privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(CriticalAction::ApproveOutboundAction), confirmed: true))
        ->toThrow(ReauthenticationRequired::class);

    $expired = new ReauthProof(
        action: CriticalAction::ChangePrivacyMode,
        nonce: 'stale',
        method: LoginMethod::Passkey,
        verifiedAt: CarbonImmutable::now('UTC')->subMinutes(10),
        expiresAt: CarbonImmutable::now('UTC')->subMinutes(8),
    );

    expect(fn (): mixed => privacyStore()->change($owner, PrivacyMode::Custom, proof: $expired, confirmed: true))
        ->toThrow(ReauthenticationRequired::class);
});

it('refuses a widening change the owner has not re-confirmed in the UI', function (): void {
    $owner = privacyOwner();

    try {
        privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(), confirmed: false);
        Assert::fail('submitting is not confirming');
    } catch (PrivacyModeChangeRefused $refused) {
        expect($refused->reason)->toBe(PrivacyModeChangeRefused::CONFIRMATION_REQUIRED);
    }
});

it('lets the owner tighten the mode with neither re-authentication nor a re-confirmation', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(), confirmed: true, organizationId: $organizationId);

    $tightened = privacyStore()->change($owner, PrivacyMode::LocalOnly, organizationId: $organizationId);

    expect($tightened->mode)->toBe(PrivacyMode::LocalOnly)
        ->and($tightened->reauthenticatedAt)->toBeNull('a tightening change records no proof because it needed none');
});

it('records the proof on the row when the change widened the boundary', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    $state = privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(), confirmed: true, organizationId: $organizationId);

    expect($state->reauthenticatedAt)->not->toBeNull()
        ->and($state->changedByUserId)->toBe((string) $owner->id)
        ->and($state->source)->toBe(PrivacyPolicyState::SOURCE_OWNER);
});

/*
 * ------------------------------------------------- the LAN opt-in, specifically
 */

it('stores the LAN opt-in only in Local Only, and only behind the widening gate', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    // RR-18: off by default.
    expect(privacyStore()->seedInstallDefault($organizationId)->allowLan)->toBeFalse();

    // Turning it on is a widening change, so it needs both gates.
    expect(fn (): mixed => privacyStore()->change($owner, PrivacyMode::LocalOnly, allowLan: true, confirmed: true, organizationId: $organizationId))
        ->toThrow(ReauthenticationRequired::class);

    $state = privacyStore()->change($owner, PrivacyMode::LocalOnly, allowLan: true, proof: privacyProof(), confirmed: true, organizationId: $organizationId);

    expect($state->allowLan)->toBeTrue()
        ->and($state->mode)->toBe(PrivacyMode::LocalOnly);

    // And it has nothing to widen in the other two modes, so it is not storable
    // there at all — which is what lets the guard treat the toggle as a single
    // branch rather than a special case per mode.
    try {
        privacyStore()->change($owner, PrivacyMode::Custom, allowLan: true, proof: privacyProof(), confirmed: true, organizationId: $organizationId);
        Assert::fail('the LAN toggle must not be storable outside Local Only');
    } catch (PrivacyModeChangeRefused $refused) {
        expect($refused->reason)->toBe(PrivacyModeChangeRefused::LAN_TOGGLE_UNSUPPORTED);
    }
});

/*
 * ------------------------------------------------------------- the audit event
 */

it('writes one named audit event per change and stores its id on the policy row', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    privacyStore()->seedInstallDefault($organizationId);
    $state = privacyStore()->change(
        actor: $owner,
        mode: PrivacyMode::Custom,
        reason: 'the owner accepted the provider terms',
        proof: privacyProof(),
        confirmed: true,
        organizationId: $organizationId,
    );

    $event = AuditEvent::query()->where('action', PrivacyModeStore::AUDIT_ACTION)->latest('occurred_at')->firstOrFail();
    $context = $event->getAttribute('context');

    expect($state->auditEventId)->toBe((string) $event->id, 'the row points at the event that recorded it')
        ->and($event->getAttribute('actor'))->toBe("user:{$owner->id}")
        ->and($event->getAttribute('subject_type'))->toBe(PrivacyModeStore::TABLE)
        ->and($event->getAttribute('subject_id'))->toBe($state->policyId)
        ->and($context['from_mode'])->toBe('LOCAL_ONLY')
        ->and($context['to_mode'])->toBe('CUSTOM')
        ->and($context['widens'])->toBeTrue()
        ->and($context['reauth_method'])->toBe(LoginMethod::Passkey->value);
});

it('writes no audit event and no row when the change is refused', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    privacyStore()->seedInstallDefault($organizationId);
    $events = AuditEvent::query()->where('action', PrivacyModeStore::AUDIT_ACTION)->count();
    $rows = DB::table(PrivacyModeStore::TABLE)->where('organization_id', $organizationId)->count();

    expect(fn (): mixed => privacyStore()->change($owner, PrivacyMode::Custom, confirmed: true, organizationId: $organizationId))
        ->toThrow(ReauthenticationRequired::class);

    expect(AuditEvent::query()->where('action', PrivacyModeStore::AUDIT_ACTION)->count())->toBe($events)
        ->and(DB::table(PrivacyModeStore::TABLE)->where('organization_id', $organizationId)->count())->toBe($rows)
        ->and(privacyStore()->active($organizationId)->mode)->toBe(PrivacyMode::LocalOnly);
});

/*
 * ------------------------------------- the surfaces reflect the change (T-68)
 */

it('reflects the change on /health/ready, so the menu bar cannot claim a stricter mode than the one in force', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    // T-68's second half is "model traffic leaves the Mac while the UI may still
    // suggest otherwise". The seed says LOCAL_ONLY; the stored policy says
    // CUSTOM; the contract document must say CUSTOM.
    config(['lea.privacy_mode' => 'LOCAL_ONLY']);
    privacyStore()->change($owner, PrivacyMode::Custom, proof: privacyProof(), confirmed: true, organizationId: $organizationId);

    app(HealthService::class)->forgetCached();

    expect(app(HealthService::class)->evaluate()->body['privacy_mode'])->toBe('CUSTOM');
});

it('reflects the change in the PWA shell the brief pages are rendered in', function (): void {
    $owner = privacyOwner();
    $organizationId = (string) Membership::query()->where('user_id', $owner->id)->value('organization_id');

    config(['lea.privacy_mode' => 'LOCAL_ONLY']);
    privacyStore()->change($owner, PrivacyMode::EuRestricted, proof: privacyProof(), confirmed: true, organizationId: $organizationId);

    actingAs($owner)
        ->get('/briefs')
        ->assertOk()
        // `layouts.app` renders the shell's privacy mode on every page, so this
        // is the assertion that the brief and chat surfaces follow the store.
        ->assertSee(__('brief.privacy_modes.EU_RESTRICTED'));
});
