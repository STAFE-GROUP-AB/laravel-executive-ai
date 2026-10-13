<?php

declare(strict_types=1);

namespace App\CRM\Projects;

/**
 * `projects.status`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
