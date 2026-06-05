<?php

namespace App\Enums;

enum RunTrigger: string
{
    case Manual = 'manual';
    case Schedule = 'schedule';
    case Import = 'import';
}
