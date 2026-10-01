<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Queued = 'queued';
    case Checking = 'checking';
    case Completed = 'completed';
    case Failed = 'failed';
}
