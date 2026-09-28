<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\DockerRegistries;
use AzerioidPanel\Broker\Vhost\DockerSettings;
use AzerioidPanel\Broker\Web\WebServers;

/**
 * Named private-registry credentials (request #6, ADR A50).
 *
 *  docker.registry.list      name, host, username — never the password
 *  docker.registry.set       name, host, username on stdin with password; replaces a same-named entry
 *  docker.registry.delete    refused while a Docker vhost still refers to it
 */
final class DockerRegistry
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        return match ($action) {
            'docker.registry.list' => ['registries' => DockerRegistries::list($runtime)],
            'docker.registry.set' => $this->set($input, $runtime),
            'docker.registry.delete' => $this->delete((string) ($args[0] ?? ($input['name'] ?? '')), $runtime, $config),
            default => throw new BrokerException('Unknown docker registry action.', 2),
        };
    }

    /** @param array<string,mixed> $input */
    private function set(array $input, Runtime $runtime): array
    {
        $name = DockerRegistries::validateName($input['name'] ?? '');
        $host = DockerRegistries::validateHost($input['host'] ?? '');
        $username = DockerRegistries::validateUsername($input['username'] ?? '');
        $password = DockerRegistries::validatePassword($input['password'] ?? '');
        DockerRegistries::save($runtime, $name, $host, $username, $password);

        return ['name' => $name, 'host' => $host, 'username' => $username, 'saved' => true];
    }

    private function delete(string $name, Runtime $runtime, Config $config): array
    {
        $name = DockerRegistries::validateName($name);
        $users = [];
        foreach (WebServers::for($config)->listVhosts($runtime, $config) as $vhost) {
            $domain = (string) ($vhost['domain'] ?? '');
            if ($domain !== '' && DockerSettings::load($runtime, $domain)['registry'] === $name) {
                $users[] = $domain;
            }
        }
        if ($users !== []) {
            throw new BrokerException("Registry {$name} is used by: " . implode(', ', $users) . '. Change those first.', 3);
        }
        DockerRegistries::delete($runtime, $name);

        return ['name' => $name, 'deleted' => true];
    }
}
