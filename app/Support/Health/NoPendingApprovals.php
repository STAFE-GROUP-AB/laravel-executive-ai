<?php

declare(strict_types=1);

namespace App\Support\Health;

final class NoPendingApprovals implements PendingApprovals
{
    public function count(): int
    {
        return 0;
    }
}
