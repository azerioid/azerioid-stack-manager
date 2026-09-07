<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\DatabaseProvisioner;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class ComponentAdopter
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adopt(string $componentId): array
    {
        $componentId = Validator::componentId($componentId);
        $registry = new ComponentRegistry($this->config->registryComponentsPath, $this->runtime);
        $definition = $registry->get($componentId);

        if ((bool) ($definition['system'] ?? false)) {
            throw new BrokerException('System components cannot be adopted.', 3);
        }

        $os = OsRelease::detect($this->runtime);
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if ($managed->has($componentId)) {
            throw new BrokerException('Component is already managed by the panel.', 3);
        }

        $detector = new ComponentDetector($this->runtime, $os, $managed);
        $status = $detector->inspect($definition);
        if (($status['kind'] ?? '') !== 'observed') {
            throw new BrokerException('Only observed (pre-existing) components can be adopted.', 3);
        }
        if (!in_array((string) ($status['status'] ?? ''), ['installed', 'active', 'broken'], true)) {
            throw new BrokerException('Component is not detected on this host.', 3);
        }

        $preflight = (new ComponentPreflight($this->config, $this->runtime, $os))->check($definition);
        if (!$preflight['ok']) {
            throw new BrokerException('Preflight failed: ' . implode(' ', $preflight['issues']), 2);
        }

        $distro = $definition['distros'][$os->distroKey];
        $unit = trim((string) ($distro['unit_name'] ?? ''));

        if (in_array($componentId, ['nginx', 'apache', 'caddy'], true)) {
            SiteWebConfig::apply($this->runtime, $this->config, $componentId, $os);
        }
        if (in_array($componentId, ['mariadb', 'postgresql'], true)) {
            (new DatabaseProvisioner($this->config, $this->runtime))->adopt($componentId);
        }
        if ($componentId === 'mongodb') {
            $logPath = rtrim($this->config->stagingDir, '/') . '/adopt-mongodb.log';
            (new MongoProvisioner($this->config, $this->runtime))->provision(new OperationLogger($this->runtime, $logPath));
        }

        ManagedManifest::record($this->runtime, $this->config->managedComponentsPath, $componentId, [
            'unit' => $unit,
            'packages' => is_array($distro['packages'] ?? null) ? $distro['packages'] : [],
            'adopted_at' => $this->runtime->now(),
            'adopted' => true,
        ]);

        $updated = (new ComponentCatalog($this->config, $this->runtime))->status($componentId);

        return [
            'component_id' => $componentId,
            'adopted' => true,
            'status' => $updated,
            'migration_note' => null,
        ];
    }
}
