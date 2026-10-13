<?php

declare(strict_types=1);

namespace App\Security\Egress;

use App\Security\Audit\AuditLog;
use Throwable;

/**
 * The product's denial recorder: one `audit_events` row per refusal, action
 * `egress.blocked`, outcome `refused`, context limited to the three facts
 * checklist row 16 asks for (requested host, resolved address, rule) and the
 * purpose that decided which rules applied.
 *
 * A failure to write the row never turns a refusal into a permission. The guard
 * has already decided by the time this runs, and an unwritable audit table —
 * a boot before migrations, a read-only disk — must not change the decision, so
 * the write is best-effort and the refusal stands either way.
 */
final readonly class AuditEgressDenialRecorder implements EgressDenialRecorder
{
    public const string ACTION = 'egress.blocked';

    public function __construct(private AuditLog $audit) {}

    public function denied(EgressRule $rule, string $requestedHost, ?string $resolvedAddress, EgressPurpose $purpose): void
    {
        try {
            $this->audit->record(
                action: self::ACTION,
                actor: 'job:EgressGuard',
                outcome: AuditLog::OUTCOME_REFUSED,
                subjectType: 'egress_target',
                subjectId: null,
                context: [
                    'rule' => $rule->value,
                    'requested_host' => $requestedHost,
                    'resolved_address' => $resolvedAddress,
                    'purpose' => $purpose->value,
                ],
            );
        } catch (Throwable) {
            //
        }
    }
}
