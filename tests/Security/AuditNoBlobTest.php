<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTransactions::class);

/*
 * ADR-003 **D7 row 8** as a schema constraint: screenshots are session-scoped files
 * in `Caches/`, deleted at session end, and `audit_events` and `tool_runs` store
 * "hash + dimensions + app/window only" (§6, ADR-005 D1.7 and D1.10).
 *
 * The test is deliberately about the *shape of the schema*, not about a row that
 * happens to be clean: a later migration that adds a blob column or a path column to
 * either table fails here, which is the only way the rule survives contact with a
 * feature that would find it convenient to keep the image.
 */

/**
 * Binary column types on either engine. SQLite reports `blob`; MySQL reports
 * `blob`, `tinyblob`, `mediumblob`, `longblob`, `binary(n)` and `varbinary(n)`.
 */
$binaryTypes = '/^(tiny|medium|long)?blob|^(var)?binary/';

/**
 * Column names that would hold a filesystem location or an inline image. `uri` and
 * `url` are deliberately absent: the §3.1 lineage column `source_uri` is on every
 * business table and is an `lxai://` locator of a *record*, never of an image.
 */
$pathLikeNames = '/(^|_)(path|paths|file|files|filename|filepath|fullpath|directory|dir|image|images|screenshot|screenshots|thumbnail|attachment|attachments|blob|bytes|binary)$/';

it('gives neither audit_events nor tool_runs a binary column', function (string $table) use ($binaryTypes): void {
    foreach (Schema::getColumns($table) as $column) {
        $type = mb_strtolower((string) $column['type']);

        expect(preg_match($binaryTypes, $type))->toBe(
            0,
            "{$table}.{$column['name']} is {$type}; ADR-003 D7 row 8 forbids a binary column here",
        );
    }
})->with(['audit_events', 'tool_runs'])->group('security');

it('gives neither audit_events nor tool_runs an image path column', function (string $table) use ($pathLikeNames): void {
    foreach (Schema::getColumns($table) as $column) {
        $name = (string) $column['name'];

        expect(preg_match($pathLikeNames, $name))->toBe(
            0,
            "{$table}.{$name} looks like a path or an inline image; ADR-003 D7 row 8 allows a hash, dimensions, app and window only",
        );
    }
})->with(['audit_events', 'tool_runs'])->group('security');

it('keeps the screenshot correlation columns a hash and dimensions', function (): void {
    // What D7 row 8 *does* allow on tool_runs: a SHA-256 of the redacted frame for
    // correlation, its dimensions, and the app and window the action targeted.
    expect(Schema::hasColumns('tool_runs', ['screenshot_sha256', 'screenshot_width', 'screenshot_height', 'app_bundle_id', 'window_title']))
        ->toBeTrue();

    $hash = collect(Schema::getColumns('tool_runs'))
        ->firstWhere('name', 'screenshot_sha256');

    expect($hash)->not->toBeNull()
        ->and(mb_strtolower((string) $hash['type']))->toStartWith('varchar');
})->group('security');

it('keeps the same rule on the proposal that carried the screenshot', function (): void {
    // `action_proposals` freezes the same correlation hash (ADR-005 D1.6) and must
    // not grow a column that could reconstruct or locate the frame either.
    foreach (Schema::getColumns('action_proposals') as $column) {
        $type = mb_strtolower((string) $column['type']);

        expect(preg_match('/^(tiny|medium|long)?blob|^(var)?binary/', $type))->toBe(
            0,
            "action_proposals.{$column['name']} is {$type}; the frozen proposal stores a hash, never the image",
        );
    }

    expect(Schema::hasColumn('action_proposals', 'screenshot_sha256'))->toBeTrue()
        ->and(Schema::hasColumn('action_proposals', 'screenshot_path'))->toBeFalse();
})->group('security');
