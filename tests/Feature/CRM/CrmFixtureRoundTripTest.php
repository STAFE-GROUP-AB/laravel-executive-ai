<?php

declare(strict_types=1);

use App\CRM\Communications\MatchedBy;
use App\CRM\Import\CrmPackMapper;
use App\Models\CRM\Commitment;
use App\Models\CRM\Communication;
use App\Models\CRM\Contact;
use App\Models\CRM\Customer;
use App\Models\CRM\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\CrmFixturePack;

uses(DatabaseTransactions::class);

/*
 * S0-18 is the acceptance oracle for S2-01: every record in
 * packaging/fixtures/crm/*.json round-trips through the §3.1 tables with
 * identical field values. Timestamps round-trip as instants rendered in UTC —
 * the pack carries no `utc_offset` of its own and §3.1 stores UTC (ADR-002 D10);
 * `customer_ids`, `contact_ids` and `assignee_user_ids` are `uniqueItems` sets in
 * crm.schema.json and come back ordered by id.
 */

/**
 * The pack as the mapper must reproduce it: instants in UTC, id sets ordered.
 *
 * @param  array<string, mixed>  $record
 * @return array<string, mixed>
 */
function normalizeCrmRecord(array $record): array
{
    foreach (['created_at', 'updated_at', 'deleted_at', 'due_at', 'occurred_at', 'promised_at'] as $key) {
        if (array_key_exists($key, $record)) {
            $record[$key] = is_string($record[$key]) ? CarbonImmutable::parse($record[$key])->utc()->toRfc3339String() : null;
        }
    }

    foreach (['customer_ids', 'contact_ids', 'assignee_user_ids'] as $key) {
        if (array_key_exists($key, $record)) {
            /** @var list<string> $ids */
            $ids = $record[$key];
            sort($ids);
            $record[$key] = $ids;
        }
    }

    if (array_key_exists('confidence', $record)) {
        $record['confidence'] = (float) $record['confidence'];
    }

    return $record;
}

it('round-trips every record of the CRM fixture pack with identical field values', function (string $entity): void {
    $pack = CrmFixturePack::import();
    $exported = app(CrmPackMapper::class)->export(CrmFixturePack::organizationId());

    expect($exported[$entity])->toHaveCount(count($pack[$entity]));

    $byId = collect($exported[$entity])->keyBy('id');

    foreach ($pack[$entity] as $record) {
        /** @var string $id */
        $id = $record['id'];
        $actual = $byId->get($id);

        expect($actual)->not->toBeNull("{$entity} {$id} is missing after import")
            ->and($actual)->toEqual(normalizeCrmRecord($record), "{$entity} {$id}");
    }
})->with(['customers', 'contacts', 'projects', 'tasks', 'communications', 'commitments']);

it('keeps the two customers that share an email domain apart', function (): void {
    CrmFixturePack::import();

    $officina = Customer::query()->findOrFail('01KKGMCCM0YSR4Q6TVT46VQE03');
    $studio = Customer::query()->findOrFail('01KS26C4008VE6T87ZXRF76DRK');

    expect($officina->email_domains)->toBe(['example.it'])
        ->and($studio->email_domains)->toBe(['example.it'])
        ->and($officina->sharesEmailDomainWith($studio))->toBeTrue()
        ->and($officina->is($studio))->toBeFalse();

    // §5.6: the domain is not a key, so a second customer on it is storable, and
    // the invoice that the domain rule matched to the wrong customer is corrected
    // on the communication row while the original match stays visible.
    $corrected = Communication::query()->findOrFail('01M4DA4CT0TWVYX223RRGM18KF');

    expect($corrected->customer_id)->toBe($studio->id)
        ->and($corrected->matched_by)->toBe(MatchedBy::User)
        ->and($corrected->confidence)->toBe(1.0)
        ->and($corrected->wasCorrectedByUser())->toBeTrue()
        ->and($corrected->correction_of['customer_id'])->toBe($officina->id)
        ->and($corrected->correction_of['matched_by'])->toBe('rule')
        ->and($corrected->correction_of['confidence'])->toBe(0.35)
        ->and($corrected->correction_of['match_reason'])->toContain('shared by 2 customers');
})->group('crm');

it('stores a contact at two customers through the customer_contact pivot', function (): void {
    CrmFixturePack::import();

    $francesca = Contact::query()->with(['customers', 'emails'])->findOrFail('01KS26Q3J0TVZ3NVFNY9VKTND0');

    expect($francesca->customers->pluck('id')->sort()->values()->all())
        ->toBe(['01KKGMCCM0YSR4Q6TVT46VQE03', '01KS26C4008VE6T87ZXRF76DRK'])
        ->and($francesca->emails)->toHaveCount(2)
        ->and($francesca->emails->where('is_primary', true)->pluck('address')->all())->toBe(['f.conti@example.it'])
        ->and($francesca->sensitivity_class->value)->toBe('personal');
})->group('crm');

it('remembers a promised follow-up and the task that honours it', function (): void {
    CrmFixturePack::import();

    $commitment = Commitment::query()->with(['followUpTask', 'communication'])->findOrFail('01M4E0J5J01J3MC6NZPVJY8QXR');

    expect($commitment->followUpTask?->id)->toBe('01M4E25E60X6CSP4R0VDN6J8PS')
        ->and($commitment->communication->id)->toBe('01M4E0J5J0CX5DFJHZJ0SX2QD6')
        ->and($commitment->brief_item_id)->toBe('01M4FJ7KVR644NVGHTFEGR4VEY')
        ->and($commitment->status->value)->toBe('open')
        ->and($commitment->direction->value)->toBe('owed_by_us')
        ->and($commitment->extracted_by->value)->toBe('ai');

    // §4.2: a promise with no stated deadline keeps none.
    expect(Commitment::query()->findOrFail('01M4DRWT00SN9DJY4VES21NQH1')->due_at)->toBeNull()
        ->and(Task::query()->findOrFail('01M3XXF0M0VY2E30S2C0FBN3ND')->due_at)->toBeNull();
})->group('crm');

it('refuses a communication whose customer does not exist', function (): void {
    CrmFixturePack::import();

    expect(fn () => DB::table('communications')->insert([
        'id' => '01M4DA4CT0TWVYX223RRGM18ZZ',
        'organization_id' => CrmFixturePack::organizationId(),
        'source_type' => 'email_message',
        'direction' => 'inbound',
        'occurred_at' => '2026-10-08 08:30:00',
        'subject' => 'Orphan',
        'excerpt' => 'No such customer.',
        'to_addresses' => '[]',
        'customer_id' => '01KS26C4008VE6T87ZXRF76DZZ',
        'match_reason' => 'none',
        'matched_by' => 'rule',
        'confidence' => 0.1,
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"sync","actor":"job:SyncSource","recorded_at":"2026-10-08T06:30:00+00:00","trace_id":null}',
    ]))->toThrow(QueryException::class);
})->group('crm');
