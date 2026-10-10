<?php

declare(strict_types=1);

namespace App\Models;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
