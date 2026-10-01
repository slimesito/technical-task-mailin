<?php

namespace App\Enums;

enum MailProvider: string
{
    case Google = 'Google Workspace';
    case Microsoft = 'Microsoft 365';
    case Other = 'Other';
    case NotDetected = 'Not Detected';
}
