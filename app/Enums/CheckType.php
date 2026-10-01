<?php

namespace App\Enums;

enum CheckType: string
{
    case Blacklist = 'blacklist';
    case Provider = 'provider';
}
