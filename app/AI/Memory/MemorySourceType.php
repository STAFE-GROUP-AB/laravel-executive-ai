<?php

declare(strict_types=1);

namespace App\AI\Memory;

/**
 * What a `memory_sources` row points at — the evidence a memory was derived from,
 * so §4.2's "every material claim carries a source reference or an explicit
 * uncertainty marker" holds for memory too. `UserStatement` is the owner saying it
 * directly; everything else is a stored row named by `source_type` plus the §3.1
 * `source_id`/`source_uri`.
 */
enum MemorySourceType: string
{
    case Communication = 'communication';
    case SourceRecord = 'source_record';
    case Brief = 'brief';
    case Task = 'task';
    case Commitment = 'commitment';
    case AiRun = 'ai_run';
    case UserStatement = 'user_statement';
}
