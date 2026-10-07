<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

final class AuditLog
{
    private const REDACT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'mysql_password',
        'passphrase', 'bot_token', 'access_key', 'access_key_id',
        'secret_access_key', 'spaces_key', 'spaces_secret', 'aws_secret_access_key',
        'content', 'content_base64', 'body', 'bytes', 'signature',
        // Container environment (A50): every value may be a secret.
        'env',
    ];

    public function __construct(private readonly Config $config, private readonly Runtime $runtime)
    {
    }

    public function write(string $action, array $args, bool $ok, int $code, ?string $error): void
    {
        $record = [
            'ts' => $this->runtime->now(),
            'action' => $action,
            'args' => self::redact($args),
            'caller_uid' => $this->runtime->getuid(),
            'ok' => $ok,
            'code' => $code,
            'error' => $error,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        $path = $this->config->auditLog;
        $dir = dirname($path);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }
        // Append under a lock. This used to read the whole log and rewrite it on
        // every broker call: O(n^2) in log size, and because the read was
        // unlocked, two concurrent callers (UI + CLI + queue worker) could each
        // write $existing . $line and silently drop the other's record. Audit
        // integrity is a security control, so losing records is not acceptable.
        $this->runtime->appendFile($path, $line, 0640);
    }

    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower((string) $k), self::REDACT_KEYS, true)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
