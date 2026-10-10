<?php

declare(strict_types=1);

namespace App\Briefs;

enum DeliveryChannel: string
{
    case Menubar = 'menubar';
    case Pwa = 'pwa';
    case Email = 'email';
}
