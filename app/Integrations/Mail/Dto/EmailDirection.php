<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * Direction of a message relative to the connection that holds it; the value set of
 * `communications.direction` in `packaging/fixtures/crm/crm.schema.json` (S0-18).
 * `Internal` covers mail between two addresses of the same connection.
 */
enum EmailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Internal = 'internal';
}
