<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\CheckOutcome;
use App\Support\Health\HealthCheck;
use App\Support\Health\LatestBrief;

/**
 * At least one brief document exists: a real brief or the install-time
 * placeholder (provider "none"), so the menu bar can always open to
 * something (contract §2, §14).
 */
final class BriefPlaceholderCheck implements HealthCheck
{
    public function __construct(private readonly LatestBrief $latestBrief) {}

    public function name(): string
    {
        return 'brief_placeholder';
    }

    public function dependsOn(): array
    {
        return ['database_migrations'];
    }

    public function run(): CheckOutcome
    {
        $brief = $this->latestBrief->resolve();

        if ($brief === null) {
            return CheckOutcome::fail('no brief document; INSTALL_CORE has not created the placeholder');
        }

        $label = $brief->isPlaceholder() ? 'placeholder brief' : 'brief';

        return CheckOutcome::ok("{$label} {$brief->document_id} v{$brief->version} present");
    }
}
