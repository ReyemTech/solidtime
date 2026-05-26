<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerPeriodUnit: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
