<?php

declare(strict_types=1);

namespace App\CRM\Contacts;

/**
 * `contact_emails.label`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum ContactEmailLabel: string
{
    case Work = 'work';
    case Personal = 'personal';
    case Other = 'other';
}
