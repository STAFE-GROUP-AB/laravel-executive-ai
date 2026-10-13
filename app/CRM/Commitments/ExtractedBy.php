<?php

declare(strict_types=1);

namespace App\CRM\Commitments;

/**
 * `commitments.extracted_by`: a model found the promise in a communication, or
 * the user recorded it (packaging/fixtures/crm/crm.schema.json).
 */
enum ExtractedBy: string
{
    case Ai = 'ai';
    case User = 'user';
}
