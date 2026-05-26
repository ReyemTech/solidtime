<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerHardCapScope: string
{
    case PerPeriod = 'per_period';
    case Cumulative = 'cumulative';
}
