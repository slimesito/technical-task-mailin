<?php

namespace App\Enums;

enum BlacklistStatus: string
{
    case Clean = 'clean';
    case Listed = 'listed';
    case Unknown = 'unknown';
}
