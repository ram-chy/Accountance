<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Adds named CHECK constraints to a table.
 *
 * Laravel's Blueprint has no check() method, and DB::statement() at the bottom
 * of every migration is easy to get wrong (the column is created before the
 * constraint exists, so a concurrent write could slip a bad row in between).
 * Wrapping them keeps the intent visible next to the columns it guards.
 *
 * MySQL 8.0.16+ enforces CHECK constraints; earlier versions parsed and
 * ignored them. The guard means a project pinned to an older server does not
 * fail to migrate - it just relies on the application-level validation, which
 * exists independently. Testing runs against MySQL, so the constraints are
 * genuinely exercised by the constraint tests rather than assumed.
 */
final class SchemaCheck
{
    public static function add(string $table, string $expression, string $name): void
    {
        if (! self::enforcedByServer()) {
            return;
        }

        DB::statement(
            "alter table `{$table}` add constraint `{$name}` check ({$expression})"
        );
    }

    private static function enforcedByServer(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            // SQLite honours CHECK constraints, but the constraint tests are
            // written against MySQL semantics (exact decimal, zero dates) and
            // the project database is MySQL. Skip rather than pretend parity.
            return false;
        }

        $version = (string) DB::selectOne('select version() as v')->v;

        // 8.0.16 is the release that began enforcing CHECK. Compare on the
        // major and minor only; "8.0.35-MySQL" must not read as lower than
        // "8.0.16".
        if (! preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }

        [$major, $minor] = [(int) $m[1], (int) $m[2]];

        if ($major > 8) {
            return true;
        }

        return $major === 8 && $minor >= 0 && version_compare($version, '8.0.16', '>=');
    }
}
