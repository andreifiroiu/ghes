<?php

declare(strict_types=1);

namespace App\Enums;

enum DevicePlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
    case Web = 'web';
}
