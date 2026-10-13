<?php

declare(strict_types=1);

namespace App\CRM\Customers;

/**
 * `customers.status`, vocabulary from packaging/fixtures/crm/crm.schema.json.
 */
enum CustomerStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Dormant = 'dormant';
    case Churned = 'churned';
}
