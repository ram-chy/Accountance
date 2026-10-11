<?php

namespace App\Enums;

/**
 * The kinds of analytical dimension this application recognises.
 *
 * The four cases are the ones the migration's CHECK constraint admits, and the
 * enum and the constraint are deliberately two copies of one list: the enum is
 * what a request rule and a report label read, the CHECK is what a hand-written
 * INSERT cannot get past. A fifth type is an additive change - one case here, one
 * value in the constraint - and nothing else in the system needs to learn a new
 * concept, because a dimension is always "a way of cutting the same ledger"
 * rather than a new kind of accounting.
 *
 * COST_CENTER is the one this phase exists for. The others are present because a
 * dimension table whose type column can only ever hold one value is a boolean
 * with a longer name, and because "which department booked this line" and "which
 * project" are the same analytical question asked of a different column.
 *
 * There is deliberately no ACCOUNT or CURRENCY type: an account is already the
 * axis every report pivots on, and a currency is money itself rather than a
 * dimension of it.
 */
enum FinancialDimensionType: string
{
    case CostCenter = 'COST_CENTER';
    case Project = 'PROJECT';
    case Department = 'DEPARTMENT';
    case Location = 'LOCATION';

    /**
     * A human label for report headers and error messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::CostCenter => 'Cost Center',
            self::Project => 'Project',
            self::Department => 'Department',
            self::Location => 'Location',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
