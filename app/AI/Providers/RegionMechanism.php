<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * How a provider's processing region is actually bound (ADR-004 D5): a distinct
 * endpoint, an account or project setting, a request parameter, or the owner's
 * own machine. A domain name is not a mechanism.
 */
enum RegionMechanism: string
{
    case Endpoint = 'endpoint';
    case AccountSetting = 'account_setting';
    case RequestParameter = 'request_parameter';
    case LocalMachine = 'local_machine';
}
