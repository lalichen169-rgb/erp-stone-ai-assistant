<?php

namespace App\Enums;

enum UserRole: string
{
    case Sales = 'sales';
    case Purchasing = 'purchasing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
