<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Relay (smarthost) configuration — a first-class outbound path, equal to direct
 * MX delivery (A36 §9.4), because most cloud providers block outbound :25.
 *
 * Secret discipline: the relay password is written only to Postfix's own
 * sasl_passwd at 0600 and immediately compiled with postmap. It never enters
 * state.json, argv, a return value, or an audit body — callers get metadata and
 * a boolean `password_set`, never the password itself.
 */
final class MailSmarthost
{
    private const SASL_PASSWD = 'sasl_passwd';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        private readonly MailPaths $paths,
    ) {
    }

    /**
     * @return array{host:string,port:int,username:string,tls:string,configured_at:string,password_set:bool}
     */
    public function set(string $host, int $port, string $username, string $password, string $tls): array
    {
        $host = Validator::smarthostHost($host);
        $port = Validator::port($port);
        $tls = self::normalizeTls($tls);
        $username = trim($username);
        if ($username === '' || strlen($username) > 255 || preg_match('/[\s:\0]/', $username) === 1) {
            throw new BrokerException('Relay username is required and must not contain spaces or colons.', 2);
        }
        if ($password === '' || str_contains($password, "\n") || str_contains($password, "\0")) {
            throw new BrokerException('Relay password is required and must be a single line.', 2);
        }

        $relay = self::relaySpec($host, $port);
        $this->writeSaslPasswd($relay, $username, $password);
        $this->applyPostfix($relay, $tls);

        $meta = [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'tls' => $tls,
            'configured_at' => $this->runtime->now(),
        ];
        (new MailState($this->runtime))->mutate(static function (array $state) use ($meta): array {
            $state['smarthost'] = $meta;

            return $state;
        });
        $this->reloadPostfix();

        return $meta + ['password_set' => true];
    }

    public function clear(): void
    {
        foreach (['relayhost', 'smtp_sasl_auth_enable', 'smtp_sasl_password_maps', 'smtp_sasl_security_options', 'smtp_sasl_tls_security_options'] as $key) {
            $this->runtime->exec(['/usr/sbin/postconf', '-X', $key], null, 30);
        }
        $this->runtime->exec(['/usr/sbin/postconf', '-e', 'smtp_tls_security_level=may'], null, 30);

        $path = $this->saslPasswdPath();
        foreach ([$path, $path . '.db'] as $file) {
            if ($this->runtime->fileExists($file)) {
                $this->runtime->deleteFile($file);
            }
        }
        (new MailState($this->runtime))->mutate(static function (array $state): array {
            $state['smarthost'] = null;

            return $state;
        });
        $this->reloadPostfix();
    }

    /**
     * Connectivity check against the configured relay. Reports reachability only —
     * a full authenticated handshake would require replaying the stored secret.
     *
     * @return array{ok:bool,host:string,port:int,latency_ms:int,banner:string,detail:string}
     */
    public function test(int $timeoutSeconds = 10): array
    {
        $meta = (new MailState($this->runtime))->load()['smarthost'];
        if (!is_array($meta) || trim((string) ($meta['host'] ?? '')) === '') {
            throw new BrokerException('No relay is configured.', 3);
        }
        $host = (string) $meta['host'];
        $port = (int) ($meta['port'] ?? 587);

        $started = microtime(true);
        $errno = 0;
        $errstr = '';
        $stream = @fsockopen($host, $port, $errno, $errstr, max(1, $timeoutSeconds));
        $latency = (int) round((microtime(true) - $started) * 1000);
        if ($stream === false) {
            return [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'latency_ms' => $latency,
                'banner' => '',
                'detail' => $errstr !== '' ? $errstr : 'Could not reach the relay host on that port.',
            ];
        }
        stream_set_timeout($stream, max(1, $timeoutSeconds));
        $banner = trim((string) @fgets($stream, 512));
        @fwrite($stream, "QUIT\r\n");
        @fclose($stream);

        return [
            'ok' => str_starts_with($banner, '220'),
            'host' => $host,
            'port' => $port,
            'latency_ms' => $latency,
            'banner' => $banner,
            'detail' => str_starts_with($banner, '220')
                ? 'Relay reachable and answering SMTP.'
                : 'Connected, but the relay did not return an SMTP greeting.',
        ];
    }

    /** Metadata safe to return to the UI/CLI — never includes the password. */
    public function describe(): ?array
    {
        $meta = (new MailState($this->runtime))->load()['smarthost'];
        if (!is_array($meta) || trim((string) ($meta['host'] ?? '')) === '') {
            return null;
        }

        return [
            'host' => (string) $meta['host'],
            'port' => (int) ($meta['port'] ?? 587),
            'username' => (string) ($meta['username'] ?? ''),
            'tls' => (string) ($meta['tls'] ?? 'starttls'),
            'configured_at' => (string) ($meta['configured_at'] ?? ''),
            'password_set' => $this->runtime->fileExists($this->saslPasswdPath()),
        ];
    }

    public static function normalizeTls(string $tls): string
    {
        $tls = strtolower(trim($tls));
        if ($tls === '' || $tls === 'starttls') {
            return 'starttls';
        }
        if (in_array($tls, ['wrapper', 'smtps', 'implicit'], true)) {
            return 'wrapper';
        }
        throw new BrokerException('Relay TLS mode must be starttls or wrapper.', 2);
    }

    /** Postfix needs [brackets] to skip the MX lookup for a relay host. */
    public static function relaySpec(string $host, int $port): string
    {
        return '[' . $host . ']:' . $port;
    }

    private function writeSaslPasswd(string $relay, string $username, string $password): void
    {
        $path = $this->saslPasswdPath();
        $this->runtime->writeFile($path, $relay . ' ' . $username . ':' . $password . "\n", 0600);
        $this->runtime->exec(['/usr/bin/chown', 'root:root', $path], null, 15);
        $this->runtime->exec(['/usr/bin/chmod', '0600', $path], null, 15);
        $postmap = $this->runtime->exec(['/usr/sbin/postmap', $path], null, 30);
        if (!$postmap->ok()) {
            throw new BrokerException('Could not compile the relay credentials map.', 1);
        }
        if ($this->runtime->fileExists($path . '.db')) {
            $this->runtime->exec(['/usr/bin/chmod', '0600', $path . '.db'], null, 15);
        }
    }

    private function applyPostfix(string $relay, string $tls): void
    {
        $settings = [
            'relayhost=' . $relay,
            'smtp_sasl_auth_enable=yes',
            'smtp_sasl_password_maps=hash:' . $this->saslPasswdPath(),
            'smtp_sasl_security_options=noanonymous',
            'smtp_sasl_tls_security_options=noanonymous',
            'smtp_tls_security_level=encrypt',
            'smtp_tls_wrappermode=' . ($tls === 'wrapper' ? 'yes' : 'no'),
        ];
        foreach ($settings as $assignment) {
            $this->runtime->exec(['/usr/sbin/postconf', '-e', $assignment], null, 30);
        }
    }

    private function saslPasswdPath(): string
    {
        return rtrim($this->paths->postfixDir(), '/') . '/' . self::SASL_PASSWD;
    }

    private function reloadPostfix(): void
    {
        $this->runtime->exec(['/usr/bin/systemctl', 'reload', $this->paths->unit('postfix')], null, 60);
    }
}
