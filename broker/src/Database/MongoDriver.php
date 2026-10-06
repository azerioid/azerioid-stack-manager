<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\ExecResult;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class MongoDriver implements DatabaseDriver
{
    /** @var list<string> */
    private const PROTECTED = ['admin', 'local', 'config'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function engine(): string
    {
        return 'mongodb';
    }

    public function isConfigured(): bool
    {
        return $this->config->mongodbUser !== '' && $this->config->mongodbPassword !== '';
    }

    public function list(): array
    {
        if (!$this->isConfigured()) {
            throw new BrokerException('MongoDB is not configured in broker.json.', 3);
        }
        $eval = 'JSON.stringify(db.adminCommand({listDatabases:1}))';
        $result = $this->runtime->exec($this->mongoshArgv(), $this->mongoshScript($eval), 30);
        if (!$result->ok()) {
            throw new BrokerException(
                trim($result->stderr) !== '' ? $this->redact(trim($result->stderr)) : 'mongosh listDatabases failed.',
                1
            );
        }
        $decoded = $this->decodeJsonObject($result->stdout);
        $usersByDb = $this->usersByDatabase();
        $databases = [];
        foreach ((array) ($decoded['databases'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $users = $usersByDb[$name] ?? [];
            if ($users === []) {
                $users = [['user' => $this->config->mongodbUser, 'host' => 'instance']];
            }
            $databases[] = [
                'name' => $name,
                'size_bytes' => (int) ($row['sizeOnDisk'] ?? 0),
                'table_count' => 0,
                'users' => $users,
                'protected' => $this->isProtected($name),
            ];
        }

        return $databases;
    }

    public function add(string $name, string $user, string $password): array
    {
        $name = Validator::dbName($name);
        $user = Validator::userName($user);
        Validator::password($password);
        $this->assertMutableDatabase($name);
        $this->assertNotPanelAdmin($user);

        $js = '(function () {'
            . 'const name = ' . $this->jsString($name) . ';'
            . 'const user = ' . $this->jsString($user) . ';'
            . 'const pwd = ' . $this->jsString($password) . ';'
            . 'const listed = db.adminCommand({listDatabases:1}).databases.map(function (d) { return d.name; });'
            . 'if (listed.indexOf(name) !== -1) { throw new Error("Database " + name + " already exists."); }'
            . 'const existing = db.adminCommand({usersInfo: {user: user, db: name}});'
            . 'if ((existing.users || []).length > 0) { throw new Error("Database user " + user + " already exists."); }'
            . 'const testdb = db.getSiblingDB(name);'
            . 'let materialized = false;'
            . 'try {'
            . 'testdb.createCollection("_azerioid_init");'
            . 'materialized = true;'
            . 'testdb.createUser({user: user, pwd: pwd, roles: [{role: "dbOwner", db: name}]});'
            . '} catch (e) {'
            . 'if (materialized) { try { testdb.dropDatabase(); } catch (ignore) {} }'
            . 'throw e;'
            . '}'
            . 'print(JSON.stringify({ok:1,name:name,user:user}));'
            . '})()';
        $this->mongoshMutate($js, 'MongoDB create');

        return [
            'name' => $name,
            'user' => $user,
            'hosts' => ['auth'],
        ];
    }

    public function delete(string $name, string $user): array
    {
        $name = Validator::dbName($name);
        $user = Validator::userName($user);
        $this->assertMutableDatabase($name);
        $this->assertNotPanelAdmin($user);

        $js = '(function () {'
            . 'const name = ' . $this->jsString($name) . ';'
            . 'const user = ' . $this->jsString($user) . ';'
            . 'const listed = db.adminCommand({listDatabases:1}).databases.map(function (d) { return d.name; });'
            . 'if (listed.indexOf(name) === -1) { throw new Error("Database does not exist."); }'
            . 'const testdb = db.getSiblingDB(name);'
            . 'try { testdb.dropUser(user); } catch (e) {}'
            . 'testdb.dropDatabase();'
            . 'print(JSON.stringify({ok:1,name:name,dropped:true}));'
            . '})()';
        $this->mongoshMutate($js, 'MongoDB drop');

        return ['name' => $name, 'user' => $user, 'dropped' => true];
    }

    public function resetPassword(string $user, string $password): array
    {
        $user = Validator::userName($user);
        Validator::password($password);
        $this->assertNotPanelAdmin($user);

        $js = '(function () {'
            . 'const user = ' . $this->jsString($user) . ';'
            . 'const pwd = ' . $this->jsString($password) . ';'
            . 'const dbs = db.adminCommand({listDatabases:1}).databases.map(function (d) { return d.name; });'
            . 'const hits = [];'
            . 'dbs.forEach(function (name) {'
            . 'if (["admin","local","config"].indexOf(name) !== -1) { return; }'
            . 'try {'
            . 'const info = db.getSiblingDB(name).getUsers();'
            . '(info.users || []).forEach(function (u) { if (u.user === user) { hits.push(name); } });'
            . '} catch (e) {}'
            . '});'
            . 'if (hits.length === 0) { throw new Error("MongoDB user " + user + " was not found on a tenant database."); }'
            . 'hits.forEach(function (name) { db.getSiblingDB(name).updateUser(user, {pwd: pwd}); });'
            . 'print(JSON.stringify({ok:1,user:user}));'
            . '})()';
        $this->mongoshMutate($js, 'MongoDB password reset');

        return ['user' => $user, 'reset' => true];
    }

    public function dump(string $name, string $outputPath): array
    {
        throw new BrokerException('MongoDB dump is not supported from this action.', 3);
    }

    /** @return array<string, list<array{user:string,host:string}>> */
    private function usersByDatabase(): array
    {
        try {
            $js = '(function () {'
                . 'const dbs = db.adminCommand({listDatabases:1}).databases.map(function (d) { return d.name; });'
                . 'const users = [];'
                . 'dbs.forEach(function (name) {'
                . 'try {'
                . 'const info = db.getSiblingDB(name).getUsers();'
                . '(info.users || []).forEach(function (u) {'
                . 'users.push({user: u.user, db: u.db || name});'
                . '});'
                . '} catch (e) {}'
                . '});'
                . 'print(JSON.stringify({users: users}));'
                . '})()';
            $result = $this->runtime->exec($this->mongoshArgv(), $this->mongoshScript($js), 30);
            if (!$result->ok()) {
                return [];
            }
            $decoded = $this->decodeJsonObject($result->stdout);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) ($decoded['users'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $user = (string) ($row['user'] ?? '');
            $db = (string) ($row['db'] ?? '');
            if ($user === '' || $db === '') {
                continue;
            }
            $out[$db][] = [
                'user' => $user,
                'host' => $db === 'admin' ? 'instance' : 'auth',
            ];
        }

        return $out;
    }

    private function mongoshMutate(string $eval, string $what): void
    {
        $result = $this->runtime->exec($this->mongoshArgv(), $this->mongoshScript($eval), 60);
        $this->requireMongoshOk($result, $what);
    }

    private function requireMongoshOk(ExecResult $result, string $what): void
    {
        $stdout = trim($result->stdout);
        $stderr = trim($result->stderr);
        if (!$result->ok()) {
            throw new BrokerException(
                $stderr !== '' ? $this->redact($stderr) : ($stdout !== '' ? $this->redact($stdout) : $what . ' failed.'),
                1
            );
        }
        if ($stdout === '') {
            return;
        }
        try {
            $decoded = $this->decodeJsonObject($stdout);
        } catch (BrokerException) {
            if (preg_match('/MongoServerError|MongoError/i', $stdout . "\n" . $stderr) === 1) {
                throw new BrokerException($this->redact($stdout !== '' ? $stdout : $stderr), 1);
            }

            return;
        }
        if (isset($decoded['ok']) && (int) $decoded['ok'] === 0) {
            throw new BrokerException((string) ($decoded['errmsg'] ?? $what . ' failed.'), 1);
        }
    }

    /**
     * A68: credentials and the script go in on stdin, never on argv. mongosh -p
     * <password> put the admin password on the child's command line, readable
     * via /proc/<pid>/cmdline by any local user (e.g. a site identity) for the
     * life of the call. mongosh executes a script piped on stdin; the script
     * authenticates in-session with db.auth(), so neither the password nor the
     * operation ever appears in argv.
     *
     * @return list<string>
     */
    private function mongoshArgv(): array
    {
        return ['/usr/bin/mongosh', '--quiet'];
    }

    /**
     * A68: the admin password travels in the stdin script now, so if mongosh
     * ever echoes a source line on error its stderr/stdout could carry the
     * secret into a BrokerException and the operation log. Strip the known
     * password from any mongosh output before it is surfaced.
     */
    private function redact(string $text): string
    {
        $pass = $this->config->mongodbPassword;

        return $pass !== '' ? str_replace($pass, '[redacted]', $text) : $text;
    }

    private function mongoshScript(string $eval): string
    {
        $user = json_encode($this->config->mongodbUser, JSON_THROW_ON_ERROR);
        $pass = json_encode($this->config->mongodbPassword, JSON_THROW_ON_ERROR);
        return 'if (!db.getSiblingDB("admin").auth(' . $user . ', ' . $pass . ')) {'
            . ' throw new Error("MongoDB authentication failed"); }' . "\n" . $eval;
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $stdout): array
    {
        $stdout = trim($stdout);
        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $stdout, $m) === 1) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        throw new BrokerException('Failed to parse MongoDB output.', 1);
    }

    private function jsString(string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new BrokerException('Failed to encode MongoDB string.', 2);
        }

        return $json;
    }

    private function assertMutableDatabase(string $name): void
    {
        if ($this->isProtected($name)) {
            throw new BrokerException('Refusing to mutate a protected system database.', 3);
        }
    }

    private function assertNotPanelAdmin(string $user): void
    {
        if ($user === $this->config->mongodbUser) {
            throw new BrokerException('Refusing to mutate the panel MongoDB admin user.', 3);
        }
    }

    private function isProtected(string $name): bool
    {
        return in_array($name, self::PROTECTED, true);
    }
}
