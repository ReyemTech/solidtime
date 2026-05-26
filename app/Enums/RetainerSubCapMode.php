<?php

declare(strict_types=1);

namespace App\Enums;

enum RetainerSubCapMode: string
{
    case Soft = 'soft';
    case Strict = 'strict';
}
