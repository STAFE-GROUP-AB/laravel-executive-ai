<?php

declare(strict_types=1);

namespace App\AI\Memory;

/**
 * What a `memories` row is scoped to (blueprint §4: conversation, memory and user
 * profile are app-owned). `Organization` is workspace-wide; `User` is the owner's
 * profile; `Customer` and `Project` attach to the CRM subject named by
 * `subject_type`/`subject_id`.
 */
enum MemoryScope: string
{
    case Organization = 'organization';
    case User = 'user';
    case Customer = 'customer';
    case Project = 'project';

    /**
     * Whether `memories.scope_key` needs an id after the scope name. Only the
     * workspace-wide scope is a bare name; the other three identify their subject —
     * `User` through `memories.user_id`, the rest through `subject_type`/`subject_id`.
     */
    public function needsSubject(): bool
    {
        return $this !== self::Organization;
    }
}
