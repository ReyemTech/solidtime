<?php

declare(strict_types=1);

namespace App\Enums;

enum RetainerPeriodMode: string
{
    case Calendar = 'calendar';
    case Anchor = 'anchor';
    case Explicit = 'explicit';
}
