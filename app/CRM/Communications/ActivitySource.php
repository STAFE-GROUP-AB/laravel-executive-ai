<?php

declare(strict_types=1);

namespace App\CRM\Communications;

/**
 * Origin of a timeline entry (blueprint §3.2 "aktivitetstidslinje ska visa källor";
 * ADR-002 D9 `communications.source_type`).
 */
enum ActivitySource: string
{
    case Email = 'email';
    case Note = 'note';
    case Task = 'task';
    case Call = 'call';
    case Meeting = 'meeting';
    case GitHub = 'github';
    case Brief = 'brief';
}
