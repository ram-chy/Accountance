<?php

namespace App\Enums;

/**
 * Which side an account normally sits on.
 *
 * `debit` means a positive balance is expressed as debits exceeding credits.
 * `credit` means the reverse. This is the concept a contra account inverts -
 * see Account::normal_balance().
 */
enum NormalBalance: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
