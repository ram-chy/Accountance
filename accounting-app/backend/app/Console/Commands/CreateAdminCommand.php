<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\RolePermissionSynchroniser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:create-admin
                            {--email= : Administrator email address}
                            {--first-name= : Administrator first name}
                            {--last-name= : Administrator last name}
                            {--password= : Administrator password (prefer the interactive prompt)}';

    /**
     * @var string
     */
    protected $description = 'Create the first administrator account (interactive, no default credentials)';

    public function handle(RolePermissionSynchroniser $synchroniser): int
    {
        $this->warn('This creates a real administrator account. Choose a strong, unique password.');

        $synchroniser->ensureBaselineRoles();

        $email = $this->option('email') ?: $this->ask('Email address');
        $firstName = $this->option('first-name') ?: $this->ask('First name');
        $lastName = $this->option('last-name') ?: $this->ask('Last name');

        $password = $this->option('password') ?: $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
        ], [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new StrongPassword],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => mb_strtolower($email),
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $user->assignRole(RoleName::Admin->value);
        $user->markEmailAsVerified();

        $this->info("Administrator [{$user->email}] created with the Admin role.");

        return self::SUCCESS;
    }
}
