<?php

declare(strict_types=1);

namespace App\CRM\Communications;

/**
 * `communications.direction`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum CommunicationDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Internal = 'internal';
}
