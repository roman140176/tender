<?php

namespace App\Enums;

enum SourceConnectionType: string
{
    case Api = 'api';
    case OpenData = 'open_data';
    case Manual = 'manual';
    case Parsing = 'parsing';
}
