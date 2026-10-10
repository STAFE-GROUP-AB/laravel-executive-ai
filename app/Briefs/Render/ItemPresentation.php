<?php

declare(strict_types=1);

namespace App\Briefs\Render;

/**
 * Badge tones and the few chrome labels the PWA shows that the mail does
 * not (delivery state). Everything the mail also prints — kinds, priorities,
 * source types, privacy modes — comes from lang/{sv,en,it,de}/brief.php
 * through BriefViewModel, so the two surfaces cannot drift.
 */
final class ItemPresentation
{
    public static function priorityTone(string $priority): string
    {
        return match ($priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'normal' => 'accent',
            default => 'neutral',
        };
    }

    public static function deliveryStateLabel(string $state): string
    {
        return match ($state) {
            'draft' => 'Draft',
            'frozen' => 'Frozen',
            'queued' => 'Queued',
            'sent' => 'Sent',
            'failed' => 'Delivery failed',
            'skipped' => 'Not sent',
            default => ucfirst($state),
        };
    }

    public static function deliveryStateTone(string $state): string
    {
        return match ($state) {
            'sent' => 'success',
            'failed' => 'danger',
            'queued' => 'info',
            default => 'neutral',
        };
    }
}
