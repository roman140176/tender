<?php

namespace App\Enums;

enum ScheduleType: string
{
    case Manual = 'manual';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Cron = 'cron';
}
