<?php

declare(strict_types=1);

namespace App\CRM\Tasks;

/**
 * `tasks.status`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 * Priority reuses App\Briefs\Priority so a task lifts into a brief unchanged.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Blocked = 'blocked';
    case Done = 'done';
}
