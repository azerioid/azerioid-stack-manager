<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreatePanelAdmin extends Command
{
    /** Distinct exit code for idempotent installer re-runs (not a genuine failure). */
    public const EXIT_ALREADY_EXISTS = 2;

    protected $signature = 'panel:create-admin
        {--email= : Admin login email}
        {--name=Admin : Display name}
        {--allowlist= : Comma-separated panel IP allowlist entries}';

    protected $description = 'Create the first admin user (installer / bootstrap — mirrors /setup without the web UI)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $existing = User::query()->orderBy('id')->value('email') ?: 'unknown';
            $this->line("Admin account already exists ({$existing}).");

            return self::EXIT_ALREADY_EXISTS;
        }

        $email = strtolower(trim((string) ($this->option('email') ?: '')));
        $name = trim((string) $this->option('name'));
        $password = (string) (getenv('PANEL_INSTALL_ADMIN_PASSWORD') ?: '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('Set PANEL_INSTALL_ADMIN_PASSWORD in the environment (not passed on argv).');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name !== '' ? $name : 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        $allowlist = trim((string) ($this->option('allowlist') ?: ''));
        if ($allowlist !== '') {
            $ips = array_values(array_filter(array_map('trim', explode(',', $allowlist))));
            $valid = [];
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $valid[] = $ip;
                    continue;
                }
                $this->warn("Skipping non-IP allowlist entry (CIDR not enforced yet): {$ip}");
            }
            if ($valid !== []) {
                Setting::put('ip_allowlist', $valid);
            }
        }

        $this->info("Admin created: {$email}");

        return self::SUCCESS;
    }
}
