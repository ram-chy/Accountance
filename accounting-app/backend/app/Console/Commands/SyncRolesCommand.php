<?php

namespace App\Console\Commands;

use App\Services\RolePermissionSynchroniser;
use Illuminate\Console\Command;

class SyncRolesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:sync-roles
                            {--dry-run : Report what would change without writing}';

    /**
     * @var string
     */
    protected $description = 'Create or update the roles and permissions declared in config/authorization.php';

    public function handle(RolePermissionSynchroniser $synchroniser): int
    {
        if ($this->option('dry-run')) {
            foreach (config('authorization.roles', []) as $role => $permissions) {
                $this->line("  role: {$role} => ".implode(', ', $permissions));
            }

            $this->line(sprintf(
                'Dry run. %d permission(s) and %d role(s) declared.',
                count(config('authorization.permissions', [])),
                count(config('authorization.roles', [])),
            ));

            return self::SUCCESS;
        }

        $changed = $synchroniser->sync();

        if ($changed === []) {
            $this->info('Roles and permissions are already up to date.');

            return self::SUCCESS;
        }

        $this->info('Synchronised roles and permissions:');
        $this->line('  '.implode(PHP_EOL.'  ', $changed));

        return self::SUCCESS;
    }
}
