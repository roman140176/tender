<?php

namespace App\Enums;

enum LawType: string
{
    case Fz44 = 'fz44';
    case Fz223 = 'fz223';
    case Commercial = 'commercial';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fz44 => '44-ФЗ',
            self::Fz223 => '223-ФЗ',
            self::Commercial => 'Коммерческая',
            self::Other => 'Прочее',
        };
    }
}
