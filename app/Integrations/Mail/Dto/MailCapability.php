<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * What a *connection* can do, derived from the provider and the scopes the user
 * actually granted (blueprint §5.6: read-only by default, send only through a
 * separately granted scope). `EmailConnection::$capabilities` carries the set; the
 * UI shows it as "scopes and rights" and the PolicyGate refuses what is absent.
 */
enum MailCapability: string
{
    case Read = 'read';
    case Search = 'search';
    case IncrementalSync = 'incremental_sync';
    case Folders = 'folders';
    case Threads = 'threads';
    case Attachments = 'attachments';
    case Drafts = 'drafts';
    case Send = 'send';
    case Push = 'push';
}
