<?php

namespace App\Enums;

enum BulkStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
}
