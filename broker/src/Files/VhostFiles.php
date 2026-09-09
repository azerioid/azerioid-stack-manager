<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Files;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;
use AzerioidPanel\Broker\Vhost\VhostUser;
use AzerioidPanel\Broker\Web\WebServers;

final class VhostFiles
{
    public const HELPER = __DIR__ . '/vhost-file-op.php';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $op, string $domain, array $input): array
    {
        $op = strtolower(trim($op));
        if (!in_array($op, VhostFileOp::OPS, true)) {
            throw new BrokerException('Unknown file operation.', 2);
        }
        $vhost = $this->eligibleVhost($domain);
        $root = (string) $vhost['root'];
        $identity = VhostUser::ensure($this->runtime, $this->config, $domain, $root);
        $path = (string) ($input['path'] ?? ($input['rel'] ?? ''));
        $dest = (string) ($input['dest'] ?? '');
        $payload = [
            'op' => $op,
            'root' => $root,
            'path' => $path,
            'dest' => $dest,
            'content_base64' => (string) ($input['content_base64'] ?? ''),
            'recursive' => (bool) ($input['recursive'] ?? false),
            'max_bytes' => $this->config->vhostFilesMaxBytes,
            'drop_user' => $identity['username'],
        ];
        $data = $this->invokeHelper($payload);
        $data['domain'] = $domain;
        $data['username'] = $identity['username'];
        $data['root'] = $root;
        $data['max_bytes'] = $this->config->vhostFilesMaxBytes;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleVhost(string $domain): array
    {
        $domain = Validator::domain($domain);
        foreach (WebServers::for($this->config)->listVhosts($this->runtime, $this->config) as $vhost) {
            if (($vhost['domain'] ?? '') !== $domain) {
                continue;
            }
            if (!empty($vhost['readonly'])) {
                throw new BrokerException('File manager is not available for read-only or system vhosts.', 3);
            }
            $root = (string) ($vhost['root'] ?? '');
            if ($root === '') {
                throw new BrokerException('Vhost has no document root.', 2);
            }

            return $vhost;
        }

        throw new BrokerException('Vhost not found.', 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeHelper(array $payload): array
    {
        $helper = self::HELPER;
        if (!$this->runtime->fileExists($helper) && !is_file($helper)) {
            throw new BrokerException('File manager helper is not installed.', 1);
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new BrokerException('Unable to encode file operation.', 1);
        }
        $timeout = 60;
        if (($payload['op'] ?? '') === 'write') {
            $timeout = 120;
        }
        $result = $this->runtime->exec([PHP_BINARY, $helper], $json, $timeout);
        $decoded = json_decode(trim($result->stdout), true);
        if (!is_array($decoded)) {
            $detail = trim($result->stderr . "\n" . $result->stdout);
            throw new BrokerException(
                'File manager helper failed.' . ($detail !== '' ? ' ' . $detail : ''),
                1
            );
        }
        if (empty($decoded['ok'])) {
            throw new BrokerException(
                (string) ($decoded['error'] ?? 'File operation failed.'),
                (int) ($decoded['code'] ?? 1)
            );
        }
        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
