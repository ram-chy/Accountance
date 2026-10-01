<?php

namespace App\Enums;

enum RoleName: string
{
    case Admin = 'Admin';
    case Accountant = 'Accountant';
    case Manager = 'Manager';
    case Staff = 'Staff';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
