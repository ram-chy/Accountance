<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    public function test_mysql_connection_works(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(DB::select('select 1 as ok')[0]->ok === 1);
    }

    public function test_server_is_mysql_8(): void
    {
        $version = DB::selectOne('select version() as version')->version;

        $this->assertStringStartsWith('8.', $version);
    }

    public function test_test_database_is_isolated_from_the_development_database(): void
    {
        $this->assertSame('accounting_test', DB::connection()->getDatabaseName());
    }

    public function test_migrations_have_run_and_tables_use_innodb_and_utf8mb4(): void
    {
        $this->assertTrue(Schema::hasTable('migrations'));

        $tables = DB::select(
            'select table_name as name, engine as engine, table_collation as collation
             from information_schema.tables
             where table_schema = database()'
        );

        $this->assertNotEmpty($tables);

        foreach ($tables as $table) {
            $this->assertSame('InnoDB', $table->engine, "Table [{$table->name}] is not InnoDB.");
            $this->assertStringStartsWith('utf8mb4', $table->collation, "Table [{$table->name}] is not utf8mb4.");
        }
    }

    public function test_migrations_can_be_rolled_back_and_re_run(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('users'));
    }
}
