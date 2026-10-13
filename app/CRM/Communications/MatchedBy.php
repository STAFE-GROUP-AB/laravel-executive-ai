<?php

declare(strict_types=1);

namespace App\CRM\Communications;

/**
 * Who linked a communication to a customer or contact (blueprint §5.6
 * "förklarlig matchning", ADR-002 D9): a deterministic rule, a model, or the
 * user correcting either. A `user` match may carry `correction_of`.
 */
enum MatchedBy: string
{
    case Rule = 'rule';
    case Ai = 'ai';
    case User = 'user';
}
