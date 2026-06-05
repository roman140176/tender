<?php

namespace App\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Error = 'error';
}
