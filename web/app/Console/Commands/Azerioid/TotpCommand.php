<?php

namespace App\Console\Commands\Azerioid;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * TOTP is panel-app state (not a broker action). Mirrors SettingsPage disable/reset
 * with the same re-auth and PANEL_REQUIRE_TOTP policy checks.
 *
 * Secrets never via argv — use AZERIOID_ADMIN_PASSWORD and AZERIOID_TOTP_CODE
 * (or an interactive prompt).
 */
class TotpCommand extends Command
{
    protected $signature = 'azerioid:totp
        {action : status|disable|reset|confirm}
        {--email= : Admin email (required for disable/reset/confirm)}
        {--json : JSON output (status)}';

    protected $description = 'Manage admin TOTP enrollment (same policy as Settings)';

    public function handle(TotpService $totp): int
    {
        return match (strtolower((string) $this->argument('action'))) {
            'status' => $this->status(),
            'disable' => $this->disable($totp),
            'reset' => $this->reset($totp),
            'confirm' => $this->confirmEnrollment($totp),
            default => $this->badAction(),
        };
    }

    private function badAction(): int
    {
        $this->error('Usage: azerioid totp status|disable|reset|confirm [--email=] [--json]');
        $this->line('Secrets via env (never argv): AZERIOID_ADMIN_PASSWORD, AZERIOID_TOTP_CODE');

        return self::INVALID;
    }

    private function status(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $query = User::query()->orderBy('id');
        if ($email !== '') {
            $query->whereRaw('lower(email) = ?', [$email]);
        }
        $users = $query->limit(50)->get();
        $rows = $users->map(static fn (User $u) => [
            'email' => $u->email,
            'enrolled' => $u->hasTwoFactorEnabled(),
            'pending' => filled($u->two_factor_secret) && $u->two_factor_confirmed_at === null,
            'require_totp' => (bool) config('azerioid.require_totp'),
        ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'require_totp' => (bool) config('azerioid.require_totp'),
                'users' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('PANEL_REQUIRE_TOTP: '.(config('azerioid.require_totp') ? 'true' : 'false'));
        $this->table(
            ['email', 'enrolled', 'pending'],
            array_map(static fn ($r) => [
                $r['email'],
                $r['enrolled'] ? 'yes' : 'no',
                $r['pending'] ? 'yes' : 'no',
            ], $rows)
        );

        return self::SUCCESS;
    }

    private function disable(TotpService $totp): int
    {
        if (config('azerioid.require_totp')) {
            $this->error('Cannot disable TOTP while PANEL_REQUIRE_TOTP is enabled for this instance.');

            return self::FAILURE;
        }

        try {
            $user = $this->resolveUser();
            $this->reauth($totp, $user);
            $totp->disable($user->fresh());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Two-factor authentication disabled for '.$user->email.'. Login is password-only.');

        return self::SUCCESS;
    }

    private function reset(TotpService $totp): int
    {
        try {
            $user = $this->resolveUser();
            $this->reauth($totp, $user);
            // Clear consumed TOTP env so confirm cannot accidentally reuse the old code.
            $secret = $totp->beginReset($user->fresh());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warn('New TOTP secret generated (unconfirmed). Old codes are no longer valid.');
        $this->line('One-time secret (will not be shown again):');
        $this->line($secret);
        $this->line('Add it to your authenticator, then confirm with a code from the NEW secret:');
        $this->line('  export AZERIOID_TOTP_CODE=<6-digit-from-new-secret>');
        $this->line('  azerioid totp confirm --email='.$user->email);

        return self::SUCCESS;
    }

    private function confirmEnrollment(TotpService $totp): int
    {
        try {
            $user = $this->resolveUser();
            if ($user->hasTwoFactorEnabled()) {
                throw new \RuntimeException('This account is already enrolled. Use totp reset to re-enroll.');
            }
            $secret = $user->plainTwoFactorSecret();
            if ($secret === null) {
                throw new \RuntimeException('No pending TOTP secret. Run totp reset first.');
            }
            $code = $this->secretFromEnv('AZERIOID_TOTP_CODE');
            if ($code === null && $this->input->isInteractive()) {
                $code = (string) $this->ask('Authenticator code for the new secret');
            }
            if ($code === null || $code === '') {
                throw new \RuntimeException('Set AZERIOID_TOTP_CODE (never argv) to confirm the new secret.');
            }
            if (! $totp->verify($secret, $code)) {
                throw new \RuntimeException('That code was not valid for the pending secret.');
            }
            $totp->confirm($user->fresh());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Authenticator confirmed for '.$user->email.'.');

        return self::SUCCESS;
    }

    private function resolveUser(): User
    {
        $email = strtolower(trim((string) $this->option('email')));
        if ($email === '') {
            throw new \RuntimeException('Provide --email=<admin@…>.');
        }
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if (! $user instanceof User) {
            throw new \RuntimeException('No admin user with that email.');
        }

        return $user;
    }

    private function reauth(TotpService $totp, User $user): void
    {
        $password = $this->secretFromEnv('AZERIOID_ADMIN_PASSWORD');
        if ($password === null && $this->input->isInteractive()) {
            $password = (string) $this->secret('Current admin password');
        }
        if ($password === null || $password === '') {
            throw new \RuntimeException(
                'Re-authentication required. Set AZERIOID_ADMIN_PASSWORD in the environment (never argv), or run interactively.'
            );
        }
        if (! Hash::check($password, $user->password)) {
            throw new \RuntimeException('Current password is incorrect.');
        }

        if ($user->hasTwoFactorEnabled()) {
            $code = $this->secretFromEnv('AZERIOID_TOTP_CODE');
            if ($code === null && $this->input->isInteractive()) {
                $code = (string) $this->ask('Current authenticator code');
            }
            if ($code === null || $code === '') {
                throw new \RuntimeException(
                    'Current authenticator code required. Set AZERIOID_TOTP_CODE in the environment (never argv).'
                );
            }
            $secret = $user->plainTwoFactorSecret();
            if ($secret === null || ! $totp->verify($secret, $code)) {
                throw new \RuntimeException('Authenticator code is not valid.');
            }
        }
    }

    private function secretFromEnv(string $name): ?string
    {
        $v = getenv($name);
        if (! is_string($v) || $v === '') {
            return null;
        }

        return $v;
    }
}
