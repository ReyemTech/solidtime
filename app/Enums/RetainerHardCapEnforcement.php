<?php
declare(strict_types=1);
namespace App\Enums;

enum RetainerHardCapEnforcement: string
{
    case Block = 'block';
    case Flag = 'flag';        // reserved for v1.1
    case Approval = 'approval'; // reserved for v1.1
}
