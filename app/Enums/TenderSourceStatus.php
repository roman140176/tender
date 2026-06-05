<?php

namespace App\Enums;

enum TenderSourceStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
