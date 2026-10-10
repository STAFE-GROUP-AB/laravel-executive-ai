<?php

declare(strict_types=1);

namespace App\Support\Data;

/**
 * Blueprint §3.1 sensitivity class, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum SensitivityClass: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Personal = 'personal';
}
