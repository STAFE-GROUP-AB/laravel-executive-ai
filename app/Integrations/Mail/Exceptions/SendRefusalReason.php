<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

/**
 * The checks every `EmailConnector::send()` performs on an ApprovedOutgoingEmail,
 * in this order, before the first byte goes to the provider (blueprint §5.6
 * criterion 8; §11). Each is recorded as the `audit_events` reason on refusal.
 */
enum SendRefusalReason: string
{
    case ConnectionMismatch = 'connection_mismatch';
    case FromNotBoundToConnection = 'from_not_bound_to_connection';
    case ApprovalExpired = 'approval_expired';
    case BodyHashMismatch = 'body_hash_mismatch';
    case SendCapabilityMissing = 'send_capability_missing';
}
