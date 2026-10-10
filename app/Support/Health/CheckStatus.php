<?php

declare(strict_types=1);

namespace App\Support\Health;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Fail = 'fail';
    case Skip = 'skip';
}
