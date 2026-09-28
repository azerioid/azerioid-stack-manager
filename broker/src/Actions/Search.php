<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Search\ElasticsearchSetup;

/**
 * Elasticsearch, host-wide (B7, ADR A54). Health and diagnostics only: no index editor, no
 * query console, no snapshots (out of scope, as the roadmap set it).
 *
 *  search.status          cluster health, version, heap, index count
 *  search.indices         name, health, docs, size
 *  search.password.reset  a new password for the elastic user, returned this once
 */
final class Search
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        if (!ManagedManifest::load($runtime, $config->managedComponentsPath)->has('elasticsearch')) {
            throw new BrokerException('Elasticsearch is not installed. Install it from Components first.', 3);
        }
        $es = new ElasticsearchSetup($config, $runtime);

        return match ($action) {
            'search.status' => $this->status($es),
            'search.indices' => ['indices' => array_values(array_map(static fn (array $i): array => [
                'name' => (string) ($i['index'] ?? ''),
                'health' => (string) ($i['health'] ?? ''),
                'docs' => (int) ($i['docs.count'] ?? 0),
                'size' => (string) ($i['store.size'] ?? ''),
            ], $es->get('/_cat/indices?format=json&bytes=b&h=index,health,docs.count,store.size')))],
            'search.password.reset' => $es->resetPassword() + [
                'url' => ElasticsearchSetup::URL,
                'note' => 'Shown once. Applications on this host connect to ' . ElasticsearchSetup::URL . ' as elastic with this password.',
            ],
            default => throw new BrokerException('Unknown search action.', 2),
        };
    }

    /** @return array<string,mixed> */
    private function status(ElasticsearchSetup $es): array
    {
        $root = $es->get('/');
        $health = $es->get('/_cluster/health');
        $nodes = $es->get('/_nodes/stats/jvm');
        $jvm = [];
        foreach ((array) ($nodes['nodes'] ?? []) as $node) {
            $jvm = (array) ($node['jvm']['mem'] ?? []);
            break;
        }

        return [
            'url' => ElasticsearchSetup::URL,
            'version' => (string) ($root['version']['number'] ?? ''),
            'cluster' => (string) ($health['cluster_name'] ?? ''),
            'status' => (string) ($health['status'] ?? 'unknown'),
            'nodes' => (int) ($health['number_of_nodes'] ?? 0),
            'active_shards' => (int) ($health['active_shards'] ?? 0),
            'unassigned_shards' => (int) ($health['unassigned_shards'] ?? 0),
            'heap_used_percent' => isset($jvm['heap_used_percent']) ? (int) $jvm['heap_used_percent'] : null,
            'heap_max_mb' => isset($jvm['heap_max_in_bytes']) ? intdiv((int) $jvm['heap_max_in_bytes'], 1048576) : null,
        ];
    }
}
