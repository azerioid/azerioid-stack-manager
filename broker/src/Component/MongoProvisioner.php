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

        // A110: authorization is never switched off. An instance that already enforces it
        // is not opened up for the panel's sake; one that does not is closed first, and the
        // panel admin is created through MongoDB's localhost exception (with authorization
        // on and no users yet, only a localhost client can create the first user, and it can
        // do nothing else). The old way opened loopback TCP with authorization disabled
        // between two restarts, for any local account to use.
        $original = $this->runtime->readFile($confPath);
        $wasEnforced = $this->authorizationEnabled($original);
        $before = $wasEnforced ? [] : $this->userNames(null);

        $log->info("Securing MongoDB in {$confPath} (localhost only, authorization on).");
        $this->runtime->writeFile($confPath, $this->setAuthorization($this->setBindLocalhost($original), true), 0644);
        $this->restartMongod($log);
        if (!$this->waitForMongo(false)) {
            throw new BrokerException('MongoDB did not become ready before user provisioning.', 1);
        }

        $log->info('Creating MongoDB panel admin user.');
        if (!$this->ensureAdminUser($password, $log)) {
            if ($wasEnforced) {
                throw new BrokerException(
                    'MongoDB already enforces authorization and has users of its own; the panel does not switch '
                    . 'authorization off to take it over. Create a root user named ' . self::ADMIN_USER
                    . ' yourself and add its credentials to broker.json, or adopt an instance without users.',
                    3
                );
            }
            if ($before === []) {
                throw new BrokerException('Failed to create MongoDB admin user.', 1);
            }
            // Users exist but authorization was off: the instance was open to every local
            // account before the panel touched it, so the localhost exception is closed and
            // a short window like the one it already had is the only way in.
            $log->warn('MongoDB had users and no authorization; creating the panel admin in a short open window.');
            $this->runtime->writeFile($confPath, $this->setAuthorization($this->readConf($confPath), false), 0644);
            $this->restartMongod($log);
            if (!$this->waitForMongo(false) || !$this->ensureAdminUser($password, $log)) {
                $this->runtime->writeFile($confPath, $this->setAuthorization($this->readConf($confPath), true), 0644);
                $this->restartMongod($log);
                throw new BrokerException('Failed to create MongoDB admin user.', 1);
            }
            $this->runtime->writeFile($confPath, $this->setAuthorization($this->readConf($confPath), true), 0644);
            $this->restartMongod($log);
        }

        if (!$this->waitForMongo(true, $password, 60)) {
            throw new BrokerException('MongoDB did not accept admin credentials after enabling auth.', 1);
        }

        if ($this->anonymousPingOk()) {
            throw new BrokerException('MongoDB still allows unauthenticated access after enabling auth.', 1);
        }

        // Nobody else may have used the way in: the admin database holds the users it had
        // before, plus the panel's, and nothing more.
        $after = $this->userNames($password);
        $unexpected = array_values(array_diff($after, $before, [self::ADMIN_USER]));
        if ($after === [] || $unexpected !== []) {
            throw new BrokerException(
                'MongoDB has user(s) the panel did not create while it was being secured: '
                . ($unexpected !== [] ? implode(', ', $unexpected) : '(none listed)') . '. Remove them and adopt again.',
                1
            );
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

    private function readConf(string $confPath): string
    {
        return $this->runtime->readFile($confPath);
    }

    private function authorizationEnabled(string $content): bool
    {
        return preg_match('/^\s*authorization\s*:\s*["\']?enabled["\']?\s*$/m', $content) === 1;
    }

    /**
     * User names in the admin database (`user@db` for users of other databases), read
     * without credentials while authorization is off or as the panel admin after.
     *
     * @return list<string>
     */
    private function userNames(?string $password): array
    {
        $script = 'const u = db.getSiblingDB("admin").system.users.find({}, {user: 1, db: 1}).toArray();'
            . ' print(JSON.stringify(u.map(x => x.db === "admin" ? x.user : x.user + "@" + x.db)));';
        $result = $password === null ? $this->mongoshScript($script, 30) : $this->mongoshScript($script, 30, $password);
        if (!$result->ok()) {
            return [];
        }
        $decoded = json_decode(trim((string) (preg_match('/\[.*\]/s', $result->stdout, $m) === 1 ? $m[0] : '')), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
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
            $result = $withAuth
                ? $this->mongoshScript('printjson(db.adminCommand({ping:1}));', 30, $password)
                : $this->mongoshScript('printjson(db.adminCommand({ping:1}));', 30);
            if ($this->mongoshOk($result)) {
                return true;
            }
            usleep(500_000);
        }

        return false;
    }

    private function ensureAdminUser(string $password, OperationLogger $log): bool
    {
        // A109: the password reaches mongosh through its environment (AZ_MONGO_PW), read
        // in the script as process.env — never as a literal, never on argv.
        $create = "printjson(db.getSiblingDB('admin').createUser({user: '" . self::ADMIN_USER
            . "', pwd: process.env.AZ_MONGO_PW, roles: [{role: 'root', db: 'admin'}]}));";
        $result = $this->mongoshScript($create, 120, null, $password);
        if ($this->mongoshOk($result)) {
            return true;
        }

        $combined = strtolower($result->stderr . "\n" . $result->stdout);
        if (str_contains($combined, 'already exists')) {
            $log->info('MongoDB admin user already exists; updating password.');
            $update = "printjson(db.getSiblingDB('admin').updateUser('" . self::ADMIN_USER
                . "', {pwd: process.env.AZ_MONGO_PW}) ?? {ok: 1});";

            return $this->mongoshOk($this->mongoshScript($update, 120, null, $password));
        }

        $log->warn(str_replace($password, '[redacted]', trim($result->stderr . ' ' . $result->stdout)));

        return false;
    }

    /**
     * A109 (A68's pattern): the script on stdin, run as one program (`--file /dev/stdin`,
     * so a failed auth guard aborts it), credentials in the environment. $authAs logs in
     * as the panel admin first; $secret only makes the password available to the script.
     */
    private function mongoshScript(string $script, int $timeoutSeconds, ?string $authAs = null, ?string $secret = null): ExecResult
    {
        $env = [];
        if ($authAs !== null) {
            $env = ['AZ_MONGO_USER' => self::ADMIN_USER, 'AZ_MONGO_PW' => $authAs];
            $script = 'if (!db.getSiblingDB("admin").auth(process.env.AZ_MONGO_USER, process.env.AZ_MONGO_PW)) {'
                . ' throw new Error("MongoDB authentication failed"); }' . "\n" . $script;
        } elseif ($secret !== null) {
            $env = ['AZ_MONGO_PW' => $secret];
        }

        return $this->runtime->exec(['/usr/bin/mongosh', '--quiet', '--file', '/dev/stdin'], $script . "\n", $timeoutSeconds, $env === [] ? null : $env);
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

    private function mongodConfPath(): ?string
    {
        return DistroPaths::for($this->runtime, $this->config)->mongodbConfig();
    }
}
