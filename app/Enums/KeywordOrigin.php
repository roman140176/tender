<?php

namespace App\Enums;

enum KeywordOrigin: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case Dictionary = 'dictionary';
}
