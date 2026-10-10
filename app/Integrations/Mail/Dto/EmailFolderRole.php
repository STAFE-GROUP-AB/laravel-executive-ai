<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

/**
 * Provider-neutral role of a folder so the unified inbox can show one Inbox over
 * eight accounts (blueprint §5.6). Gmail system labels (`INBOX`, `SENT`, ...),
 * Graph well-known folder names (`inbox`, `sentitems`, ...) and IMAP SPECIAL-USE
 * attributes (RFC 6154 `\Sent`, `\Junk`, ...) all map here; anything unmapped is
 * `Other`. `All` is Gmail's "All Mail" and Graph's `msgfolderroot` equivalent.
 */
enum EmailFolderRole: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Archive = 'archive';
    case Trash = 'trash';
    case Spam = 'spam';
    case All = 'all';
    case Other = 'other';
}
