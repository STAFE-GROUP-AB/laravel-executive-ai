<?php

declare(strict_types=1);

namespace App\Briefs;

/**
 * Shared with CRM tasks so a task can be lifted into a brief unchanged.
 */
enum Priority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
