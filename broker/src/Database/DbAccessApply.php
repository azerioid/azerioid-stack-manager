<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class DbAccessApply
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @param  list<string>|string  $ips
     * @return array<string, mixed>
     */
    public function set(string $engine, string $name, string $mode, array|string $ips, string $confirm): array
    {
        $manager = new DatabaseManager($this->config, $this->runtime);
        $engine = $manager->resolveEngine($engine);
        $name = Validator::dbName($name);
        $mode = Validator::accessMode($mode);
        $ips = Validator::accessIps($mode, $ips);
        if ($mode === 'global') {
            Validator::typedConfirm($confirm, Validator::GLOBAL_ACCESS_CONFIRM);
        }

        $driver = $manager->driver($engine);
        $db = $this->findDatabase($driver, $name);
        if (!empty($db['protected'])) {
            throw new BrokerException('Refusing to change access on a protected system database.', 3);
        }

        $store = new DbAccessStore($this->runtime);
        $before = $store->get($engine, $name);
        $instanceBefore = DbAccessPolicy::aggregate($store->engineEntries($engine));
        $user = $this->databaseUser($db, $name);

        $engineResult = match ($engine) {
            'mariadb' => (new MariaDBAccess($this->runtime))->apply($name, $user, $mode, $ips),
            'postgresql' => (new PostgreSQLAccess($this->runtime))->apply($name, $user, $mode, $ips),
            'mongodb' => (new MongoAccess())->apply($name, $mode, $ips),
            default => throw new BrokerException('Unknown database engine.', 2),
        };

        $store->put($engine, $name, $mode, $ips);
        if ($engine === 'mongodb' && $mode !== 'localhost') {
            $fw = new DbAccessFirewall($this->runtime);
            if (!$fw->isActive()) {
                $store->put($engine, $name, $before['mode'], $before['ips']);
                throw new BrokerException(
                    'MongoDB remote access requires an active ufw or firewalld. Refusing to bind port 27017 publicly — MongoDB cannot restrict clients per database or per host.',
                    3
                );
            }
        }
        $network = $this->syncNetwork($engine, $store);
        $after = $store->get($engine, $name);
        $instance = $network['aggregate'];
        $access = DbAccessPolicy::describe($engine, $after, $instance);

        return [
            'engine' => $engine,
            'name' => $name,
            'user' => $user,
            'before' => DbAccessPolicy::describe($engine, $before, $instanceBefore),
            'after' => $access,
            'engine_result' => $engineResult,
            'bind' => $network['bind'],
            'firewall' => $network['firewall'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $engine, string $name): array
    {
        $manager = new DatabaseManager($this->config, $this->runtime);
        $engine = $manager->resolveEngine($engine);
        $name = Validator::dbName($name);
        $driver = $manager->driver($engine);
        $this->findDatabase($driver, $name);
        $store = new DbAccessStore($this->runtime);
        $requested = $store->get($engine, $name);
        $instance = DbAccessPolicy::aggregate($store->engineEntries($engine));

        return [
            'engine' => $engine,
            'name' => $name,
            'access' => DbAccessPolicy::describe($engine, $requested, $instance),
        ];
    }

    /**
     * Annotate db.list rows with access descriptors.
     *
     * @param  list<array<string, mixed>>  $databases
     * @return list<array<string, mixed>>
     */
    public function annotate(string $engine, array $databases): array
    {
        $store = new DbAccessStore($this->runtime);
        $instance = DbAccessPolicy::aggregate($store->engineEntries($engine));
        foreach ($databases as $i => $db) {
            $name = (string) ($db['name'] ?? '');
            $requested = $name !== '' ? $store->get($engine, $name) : ['mode' => 'localhost', 'ips' => [], 'updated_at' => null];
            $databases[$i]['access'] = DbAccessPolicy::describe($engine, $requested, $instance);
        }

        return $databases;
    }

    public function forgetAndSync(string $engine, string $name): void
    {
        $store = new DbAccessStore($this->runtime);
        $store->forget($engine, $name);
        $this->syncNetwork($engine, $store);
    }

    /**
     * @return array{aggregate: array{network:string,ips:list<string>,bind_public:bool}, bind: array<string,mixed>, firewall: array<string,mixed>}
     */
    private function syncNetwork(string $engine, DbAccessStore $store): array
    {
        $aggregate = DbAccessPolicy::aggregate($store->engineEntries($engine));
        $bind = (new DbAccessBind($this->config, $this->runtime))->sync($engine, $aggregate['bind_public']);
        $firewall = (new DbAccessFirewall($this->runtime))->sync(
            $engine,
            DbAccessPolicy::port($engine),
            $aggregate
        );

        return [
            'aggregate' => $aggregate,
            'bind' => $bind,
            'firewall' => $firewall,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findDatabase(DatabaseDriver $driver, string $name): array
    {
        foreach ($driver->list() as $db) {
            if (($db['name'] ?? '') === $name) {
                return $db;
            }
        }
        throw new BrokerException('Database does not exist.', 3);
    }

    /** @param  array<string, mixed>  $db */
    private function databaseUser(array $db, string $fallback): string
    {
        $users = $db['users'] ?? [];
        if (is_array($users)) {
            foreach ($users as $row) {
                $user = is_array($row) ? (string) ($row['user'] ?? '') : '';
                if ($user !== '') {
                    try {
                        return Validator::userName($user);
                    } catch (BrokerException) {
                    }
                }
            }
        }

        return Validator::userName($fallback);
    }
}
