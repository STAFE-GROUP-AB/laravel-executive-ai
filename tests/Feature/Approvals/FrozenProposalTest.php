<?php

declare(strict_types=1);

use App\AI\Memory\MemoryScope;
use App\Approvals\FrozenProposalException;
use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\Models\AI\AiRun;
use App\Models\AI\Memory;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/*
 * S2-02: the §11 guarantees that are *not* expressible as a unique index — a frozen
 * proposal's content cannot change, and an approval authorises only the content the
 * approver actually saw. The cross-row guarantees (one approval per proposal, one
 * nonce ever) are indexes and live in the backend-contract suite.
 */

$freeze = function (Organization $organization, array $payload = ['kind' => 'click', 'element_id' => 'AXButton-1']): ActionProposal {
    $frozenAt = CarbonImmutable::parse('2026-10-12T06:05:00Z');

    return ActionProposal::query()->create([
        'organization_id' => $organization->id,
        'kind' => ProposalKind::UiAction,
        'status' => ProposalStatus::Pending,
        'proposed_by' => 'ai:ollama/llama3.2:3b',
        'app_bundle_id' => 'com.apple.Safari',
        'window_title' => 'Invoice 2026-114 — Acme',
        'element_role' => 'AXButton',
        'element_label' => 'Send',
        'action_type' => 'click',
        'payload' => $payload,
        'proposal_hash' => ActionProposal::hashPayload($payload),
        'nonce' => bin2hex(random_bytes(16)),
        'idempotency_key' => (string) Str::ulid(),
        'frozen_at' => $frozenAt,
        'expires_at' => $frozenAt->addMinutes(15),
        'sensitivity_class' => 'internal',
        'provenance' => Provenance::job('ProposeUiAction', ProvenanceOrigin::AiExtraction),
    ]);
};

it('hashes the frozen payload with the RFC 8785 canonical form', function (): void {
    $hash = ActionProposal::hashPayload(['b' => 2, 'a' => 1]);

    expect($hash)->toBe(ActionProposal::hashPayload(['a' => 1, 'b' => 2]))
        ->and($hash)->toStartWith('sha256:')
        ->and($hash)->toHaveLength(71)
        ->and($hash)->toBe('sha256:'.hash('sha256', '{"a":1,"b":2}'));
});

it('refuses to persist a change to the frozen content of a proposal', function () use ($freeze): void {
    $proposal = $freeze(Organization::factory()->create());

    $proposal->payload = ['kind' => 'type', 'text' => 'ok'];

    expect(fn () => $proposal->save())
        ->toThrow(FrozenProposalException::class);

    expect($proposal->fresh()->payload)->toBe(['kind' => 'click', 'element_id' => 'AXButton-1']);
});

it('names every frozen column the save tried to change', function () use ($freeze): void {
    $proposal = $freeze(Organization::factory()->create());

    $proposal->window_title = 'Something else';
    $proposal->expires_at = CarbonImmutable::parse('2027-01-01T00:00:00Z');

    $columns = [];
    $proposalId = null;

    try {
        $proposal->save();
    } catch (FrozenProposalException $exception) {
        $columns = $exception->columns;
        $proposalId = $exception->proposalId;
    }

    expect($columns)->toContain('window_title')->toContain('expires_at')
        ->and($proposalId)->toBe($proposal->getKey());
});

it('still records what happened to the proposal after freeze', function () use ($freeze): void {
    $proposal = $freeze(Organization::factory()->create());

    $proposal->status = ProposalStatus::Executed;
    $proposal->executed_at = CarbonImmutable::parse('2026-10-12T06:11:00Z');
    $proposal->save();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Executed);
});

it('is approvable only while pending, unexpired and hash-consistent', function () use ($freeze): void {
    $proposal = $freeze(Organization::factory()->create());
    $inside = CarbonImmutable::parse('2026-10-12T06:10:00Z');
    $outside = CarbonImmutable::parse('2026-10-12T06:30:00Z');

    expect($proposal->isApprovable($inside))->toBeTrue()
        ->and($proposal->isApprovable($outside))->toBeFalse();

    // A payload edited around the model (raw update) is detected by the hash.
    DB::table('action_proposals')->where('id', $proposal->getKey())
        ->update(['payload' => json_encode(['kind' => 'type', 'text' => 'ok'])]);

    expect($proposal->fresh()->hashMatchesPayload())->toBeFalse()
        ->and($proposal->fresh()->isApprovable($inside))->toBeFalse();
});

it('authorises only the proposal and the content the approver saw', function () use ($freeze): void {
    $organization = Organization::factory()->create();
    $approver = User::factory()->create();
    $proposal = $freeze($organization);
    $other = $freeze($organization, ['kind' => 'click', 'element_id' => 'AXButton-2']);
    $now = CarbonImmutable::parse('2026-10-12T06:10:00Z');

    $approval = ActionApproval::query()->create([
        'organization_id' => $organization->id,
        'action_proposal_id' => $proposal->id,
        'nonce' => $proposal->nonce,
        'proposal_hash' => $proposal->proposal_hash,
        'approver_user_id' => $approver->id,
        'approved_at' => $now,
        'expires_at' => $proposal->expires_at,
        'reauth_at' => $now,
        'sensitivity_class' => 'internal',
        'provenance' => Provenance::job('ApproveUiAction', ProvenanceOrigin::Manual),
    ]);

    expect($approval->authorises($proposal, $now))->toBeTrue()
        ->and($approval->authorises($other, $now))->toBeFalse('an approval is bound to one proposal')
        ->and($approval->authorises($proposal, CarbonImmutable::parse('2026-10-12T06:30:00Z')))->toBeFalse('an expired approval authorises nothing');

    $approval->consumed_at = $now;
    $approval->save();

    expect($approval->authorises($proposal, $now))->toBeFalse('a spent nonce authorises nothing');
});

it('hides the credential-adjacent and replay-sensitive columns from serialisation', function () use ($freeze): void {
    $organization = Organization::factory()->create();
    $proposal = $freeze($organization);

    $approval = ActionApproval::query()->create([
        'organization_id' => $organization->id,
        'action_proposal_id' => $proposal->id,
        'nonce' => $proposal->nonce,
        'proposal_hash' => $proposal->proposal_hash,
        'approver_user_id' => User::factory()->create()->id,
        'approved_at' => CarbonImmutable::parse('2026-10-12T06:10:00Z'),
        'expires_at' => $proposal->expires_at,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Safari',
        'sensitivity_class' => 'internal',
        'provenance' => Provenance::job('ApproveUiAction', ProvenanceOrigin::Manual),
    ]);

    $keys = array_keys($approval->toArray());

    expect($keys)->not->toContain('nonce')
        ->and($keys)->not->toContain('ip_address')
        ->and($keys)->not->toContain('user_agent');
});

it('buckets the dedupe key into the window ADR-004 D7 configures', function (): void {
    $key = AiRun::dedupeKey('ComposeBrief', ['period' => '2026-W41', 'org' => '01J'], 'llama3.2:3b');

    expect($key)->toStartWith('sha256:')->toHaveLength(71)
        ->and($key)->toBe(AiRun::dedupeKey('ComposeBrief', ['org' => '01J', 'period' => '2026-W41'], 'llama3.2:3b'))
        ->and($key)->not->toBe(AiRun::dedupeKey('ComposeBrief', ['period' => '2026-W41', 'org' => '01J'], 'phi4-mini'))
        ->and($key)->not->toBe(AiRun::dedupeKey('SummarizeThread', ['period' => '2026-W41', 'org' => '01J'], 'llama3.2:3b'));

    // Length-prefixing means a boundary cannot be shifted between the three parts.
    expect(AiRun::dedupeKey('Compose', 'Brief', 'm'))->not->toBe(AiRun::dedupeKey('ComposeBrief', '', 'm'));

    expect(AiRun::dedupeWindowStart(CarbonImmutable::parse('2026-10-12T06:59:59Z'))->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-10-12T06:00:00Z')->toIso8601String())
        ->and(AiRun::dedupeWindowStart(CarbonImmutable::parse('2026-10-12T07:00:00Z'))->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-10-12T07:00:00Z')->toIso8601String())
        ->and(AiRun::dedupeWindowStart(CarbonImmutable::parse('2026-10-12T06:37:00Z'), 15)->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-10-12T06:30:00Z')->toIso8601String());
});

it('builds the non-null scope key the memory uniqueness index needs', function (): void {
    expect(Memory::scopeKeyFor(MemoryScope::Organization))->toBe('organization')
        ->and(Memory::scopeKeyFor(MemoryScope::User, '01J'))->toBe('user:01J')
        ->and(Memory::scopeKeyFor(MemoryScope::Customer, '01K'))->toBe('customer:01K');

    expect(fn (): string => Memory::scopeKeyFor(MemoryScope::Project))
        ->toThrow(InvalidArgumentException::class);
});
