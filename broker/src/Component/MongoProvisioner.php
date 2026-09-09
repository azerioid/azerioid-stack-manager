<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\BrokerConfigWriter;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Os\DistroPaths;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Secrets;

final class MongoProvisioner
{
    private const ADMIN_USER = 'azerioid_panel_admin';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function provision(OperationLogger $log): void
    {
        $password = Secrets::generatePassword();
        $configPath = getenv('AZERIOID_PANEL_CONFIG') ?: getenv('LACMP_PANEL_CONFIG') ?: '/etc/azerioid-panel/broker.json';
        $confPath = $this->mongodConfPath();
        if ($confPath === null) {
            throw new BrokerException('mongod.conf not found.', 1);
        }

        $log->info("Securing MongoDB bind address in {$confPath}");
        $content = $this->setBindLocalhost($this->runtime->readFile($confPath));
        $content = $this->setAuthorization($content, false);
        $this->runtime->writeFile($confPath, $content, 0644);
        $this->restartMongod($log);

        if (!$this->waitForMongo(false)) {
            throw new BrokerException('MongoDB did not become ready before user provisioning.', 1);
        }

        $log->info('Creating MongoDB panel admin user.');
        if (!$this->ensureAdminUser($password, $log)) {
            throw new BrokerException('Failed to create MongoDB admin user.', 1);
        }

        $log->info('Enabling MongoDB authorization.');
        $content = $this->setAuthorization($this->runtime->readFile($confPath), true);
        $this->runtime->writeFile($confPath, $content, 0644);
        $this->restartMongod($log);

        if (!$this->waitForMongo(true, $password, 60)) {
            throw new BrokerException('MongoDB did not accept admin credentials after enabling auth.', 1);
        }

        if ($this->anonymousPingOk()) {
            throw new BrokerException('MongoDB still allows unauthenticated access after enabling auth.', 1);
        }

        BrokerConfigWriter::merge($this->runtime, $configPath, [
            'mongodb' => [
                'host' => '127.0.0.1',
                'port' => 27017,
                'user' => self::ADMIN_USER,
                'password' => $password,
            ],
        ]);
        $log->info('MongoDB secured (localhost + auth). SSPL license applies to mongodb-org packages.');
    }

    private function setBindLocalhost(string $content): string
    {
        if (preg_match('/^\s*bindIp\s*:/m', $content) === 1) {
            return preg_replace('/^\s*bindIp\s*:.*/m', '  bindIp: 127.0.0.1', $content) ?? $content;
        }

        if (preg_match('/^net\s*:/m', $content) === 1) {
            return preg_replace('/^net\s*:/m', "net:\n  bindIp: 127.0.0.1", $content, 1) ?? $content;
        }

        return rtrim($content) . "\nnet:\n  bindIp: 127.0.0.1\n";
    }

    private function setAuthorization(string $content, bool $enabled): string
    {
        $value = $enabled ? 'enabled' : 'disabled';
        if (preg_match('/^\s*authorization\s*:/m', $content) === 1) {
            return preg_replace('/^\s*authorization\s*:.*/m', '  authorization: ' . $value, $content) ?? $content;
        }
        if (preg_match('/^security\s*:/m', $content) === 1) {
            return preg_replace('/^security\s*:/m', "security:\n  authorization: {$value}", $content, 1) ?? $content;
        }

        return rtrim($content) . "\nsecurity:\n  authorization: {$value}\n";
    }

    private function restartMongod(OperationLogger $log): void
    {
        $result = $this->runtime->exec(['/usr/bin/systemctl', 'restart', 'mongod'], null, 120);
        if (!$result->ok()) {
            throw new BrokerException('Failed to restart mongod.', 1);
        }
        $log->info('Restarted mongod.');
    }

    private function waitForMongo(bool $withAuth, string $password = '', int $attempts = 30): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($withAuth) {
                $result = $this->runtime->exec(
                    $this->mongoshAuthArgv($password, "db.adminCommand({ping:1})"),
                    null,
                    30
                );
                if ($this->mongoshOk($result)) {
                    return true;
                }
            } else {
                $result = $this->runtime->exec(
                    ['/usr/bin/mongosh', '--quiet', '--eval', 'db.adminCommand({ping:1})'],
                    null,
                    30
                );
                if ($this->mongoshOk($result)) {
                    return true;
                }
            }
            usleep(500_000);
        }

        return false;
    }

    private function ensureAdminUser(string $password, OperationLogger $log): bool
    {
        $escaped = str_replace("'", "\\'", $password);
        $create = "db.getSiblingDB('admin').createUser({user: '" . self::ADMIN_USER
            . "', pwd: '{$escaped}', roles: [{role: 'root', db: 'admin'}]});";
        $result = $this->mongoshEval($create, 120);
        if ($this->mongoshOk($result)) {
            return true;
        }

        $combined = strtolower($result->stderr . "\n" . $result->stdout);
        if (str_contains($combined, 'already exists')) {
            $log->info('MongoDB admin user already exists; updating password.');
            $update = "db.getSiblingDB('admin').updateUser('" . self::ADMIN_USER
                . "', {pwd: '{$escaped}'});";

            return $this->mongoshOk($this->mongoshEval($update, 120));
        }

        $log->warn(trim($result->stderr . ' ' . $result->stdout));

        return false;
    }

    private function mongoshEval(string $script, int $timeoutSeconds): ExecResult
    {
        return $this->runtime->exec(
            ['/usr/bin/mongosh', '--quiet', '--eval', $script],
            null,
            $timeoutSeconds
        );
    }

    private function mongoshOk(ExecResult $result): bool
    {
        if (!$result->ok()) {
            return false;
        }

        $combined = strtolower($result->stderr . "\n" . $result->stdout);

        return str_contains($combined, 'ok')
            && !str_contains($combined, 'error')
            && !str_contains($combined, 'unauthorized');
    }

    private function anonymousPingOk(): bool
    {
        // ping succeeds without credentials on localhost even when auth is enabled;
        // use a command that always requires authentication.
        $result = $this->runtime->exec(
            ['/usr/bin/mongosh', '--quiet', '--eval', 'db.adminCommand({listDatabases:1})'],
            null,
            15
        );

        return $result->ok() && str_contains($result->stdout, 'databases');
    }

    /** @return list<string> */
    private function mongoshAuthArgv(string $password, string $eval): array
    {
        return [
            '/usr/bin/mongosh',
            '--quiet',
            '-u',
            self::ADMIN_USER,
            '-p',
            $password,
            '--authenticationDatabase',
            'admin',
            '--eval',
            $eval,
        ];
    }

    private function mongodConfPath(): ?string
    {
        return DistroPaths::for($this->runtime, $this->config)->mongodbConfig();
    }
}
