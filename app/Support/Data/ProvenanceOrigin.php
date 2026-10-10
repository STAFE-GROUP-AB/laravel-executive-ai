<?php

declare(strict_types=1);

namespace App\Support\Data;

/**
 * Blueprint §3.1 provenance origin, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum ProvenanceOrigin: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Sync = 'sync';
    case AiExtraction = 'ai_extraction';
    case UserCorrection = 'user_correction';
}
