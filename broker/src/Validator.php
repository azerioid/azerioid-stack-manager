<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

final class Validator
{
    public const DOMAIN_PATTERN = '/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/';
    public const DB_NAME_PATTERN = '/^[a-zA-Z0-9_]{1,32}$/';
    public const PHP_VERSION_PATTERN = '/^[0-9]+\.[0-9]+$/';
    public const SERVICE_PATTERN = '/^[a-z][a-z0-9@._-]{0,63}$/';
    public const ACTION_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*)+$/';
    public const COMPONENT_ID_PATTERN = '/^[a-z][a-z0-9.-]{0,63}$/';
    public const SUPERVISOR_PROGRAM_PATTERN = '/^[a-z][a-z0-9_-]{0,48}$/';
    public const SUPERVISOR_COMMAND_PATTERN = '/^[^\0\n\r;|&`$]{1,512}$/u';
    public const INI_KEY_PATTERN = '/^[a-zA-Z0-9_.]{1,64}$/';
    public const LOCAL_UPSTREAM_PATTERN = '/^127\.0\.0\.1:([1-9][0-9]{0,4})$/';

    public const ALLOWED_VHOST_TYPES = ['php', 'static', 'proxy'];

    public const ALLOWED_PHP_INI_KEYS = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_time',
        'max_file_uploads',
        'expose_php',
    ];

    public const SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'lacmp_panel',
    ];

    public static function action(string $action): string
    {
        $action = strtolower(trim($action));
        if ($action === '' || !preg_match(self::ACTION_PATTERN, $action)) {
            throw new BrokerException('Unknown or invalid action.', 2);
        }
        return $action;
    }

    public static function componentId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match(self::COMPONENT_ID_PATTERN, $id)) {
            throw new BrokerException('Invalid component id.', 2);
        }
        return $id;
    }

    public static function operationId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) {
            throw new BrokerException('Invalid operation id.', 2);
        }
        return $id;
    }

    public static function domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match(self::DOMAIN_PATTERN, $domain)) {
            throw new BrokerException('Invalid domain name.', 2);
        }
        return $domain;
    }

    public static function dbName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match(self::DB_NAME_PATTERN, $name)) {
            throw new BrokerException('Invalid database or user name.', 2);
        }
        if (in_array(strtolower($name), self::SYSTEM_DATABASES, true)) {
            throw new BrokerException('Refusing to mutate a protected system database.', 3);
        }
        return $name;
    }

    public static function userName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match(self::DB_NAME_PATTERN, $name)) {
            throw new BrokerException('Invalid database user name.', 2);
        }
        $reserved = ['root', 'mysql', 'mariadb.sys', 'PUBLIC', ''];
        if (in_array($name, $reserved, true) || strtolower($name) === 'root') {
            throw new BrokerException('Refusing to mutate a reserved database user.', 3);
        }
        return $name;
    }

    public static function phpVersion(string $version, array $installed): string
    {
        $version = trim($version);
        if ($version === '' || !preg_match(self::PHP_VERSION_PATTERN, $version)) {
            throw new BrokerException('Invalid PHP version.', 2);
        }
        if (!in_array($version, $installed, true)) {
            throw new BrokerException('PHP version is not installed.', 2);
        }
        return $version;
    }

    public static function vhostType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::ALLOWED_VHOST_TYPES, true)) {
            throw new BrokerException('Invalid vhost type. Allowed: php, static, proxy.', 2);
        }
        return $type;
    }

    public static function vhostEngine(string $engine): string
    {
        return \AzerioidPanel\Broker\Web\VhostEngine::normalize($engine);
    }

    public static function localUpstream(string $upstream): string
    {
        $upstream = trim($upstream);
        if (!preg_match(self::LOCAL_UPSTREAM_PATTERN, $upstream, $m)) {
            throw new BrokerException('Upstream must be 127.0.0.1:<port>.', 2);
        }
        $port = (int) $m[1];
        if ($port > 65535) {
            throw new BrokerException('Upstream port is out of range.', 2);
        }
        return $upstream;
    }

    /**
     * Web roots must stay under the allowlisted base. Rejects traversal,
     * NUL bytes, and symlink escapes (resolved against the live tree).
     */
    public static function webRoot(string $path, string $base, Runtime $runtime): string
    {
        $path = trim($path);
        $base = rtrim($base, '/');

        if ($path === '' || str_contains($path, "\0")) {
            throw new BrokerException('Invalid web root path.', 2);
        }
        if (!str_starts_with($path, '/')) {
            throw new BrokerException('Web root must be an absolute path.', 2);
        }
        if (str_contains($path, '..')) {
            throw new BrokerException('Web root must not contain "..".', 2);
        }

        $normalized = self::normalizeAbsolute($path);
        $baseNormalized = self::normalizeAbsolute($base);

        if (!str_starts_with($normalized . '/', $baseNormalized . '/') && $normalized !== $baseNormalized) {
            throw new BrokerException('Web root is outside the allowlisted base.', 2);
        }

        $resolved = $runtime->resolveUnderBase($normalized, $baseNormalized);
        if ($resolved === null) {
            throw new BrokerException('Web root escapes the allowlisted base (symlink or missing parent).', 2);
        }

        return $resolved;
    }

    public static function supervisorProgramName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match(self::SUPERVISOR_PROGRAM_PATTERN, $name)) {
            throw new BrokerException('Invalid supervisor program name.', 2);
        }
        if (str_starts_with($name, 'azerioid-')) {
            throw new BrokerException('Program name must not include the azerioid- prefix (added automatically).', 2);
        }

        return $name;
    }

    public static function supervisorCommand(string $command): string
    {
        $command = trim($command);
        if ($command === '' || !preg_match(self::SUPERVISOR_COMMAND_PATTERN, $command)) {
            throw new BrokerException('Invalid supervisor command.', 2);
        }

        return $command;
    }

    /**
     * Working directory for supervised processes: under www_root or the dedicated supervised home.
     */
    public static function supervisedDirectory(string $path, string $wwwRoot, Runtime $runtime): string
    {
        try {
            return self::webRoot($path, $wwwRoot, $runtime);
        } catch (BrokerException) {
            // fall through
        }

        $home = \AzerioidPanel\Broker\Supervisor\SupervisedUser::HOME;
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            throw new BrokerException('Invalid working directory.', 2);
        }
        if (!str_starts_with($path, '/')) {
            throw new BrokerException('Working directory must be an absolute path.', 2);
        }
        $normalized = self::normalizeAbsolute($path);
        $homeNormalized = self::normalizeAbsolute($home);
        if (!str_starts_with($normalized . '/', $homeNormalized . '/') && $normalized !== $homeNormalized) {
            throw new BrokerException('Working directory must be under the web root or supervised home.', 2);
        }

        return $normalized;
    }

    public static function service(string $name, array $allowed): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match(self::SERVICE_PATTERN, $name)) {
            throw new BrokerException('Invalid service name.', 2);
        }
        if (!in_array($name, $allowed, true)) {
            throw new BrokerException('Service is not in the AZERIOID control allowlist.', 3);
        }
        return $name;
    }

    public static function logKey(string $key, array $allowed): string
    {
        $key = trim($key);
        if ($key === '' || !isset($allowed[$key])) {
            throw new BrokerException('Unknown log key.', 2);
        }
        return $key;
    }

    public static function lineCount(string|int $lines, int $max = 500): int
    {
        if (!is_numeric($lines)) {
            throw new BrokerException('Line count must be numeric.', 2);
        }
        $n = (int) $lines;
        if ($n < 1 || $n > $max) {
            throw new BrokerException("Line count must be between 1 and {$max}.", 2);
        }
        return $n;
    }

    public static function phpIniKey(string $key): string
    {
        $key = trim($key);
        if (!preg_match(self::INI_KEY_PATTERN, $key) || !in_array($key, self::ALLOWED_PHP_INI_KEYS, true)) {
            throw new BrokerException('php.ini key is not on the allowlist.', 2);
        }
        return $key;
    }

    public static function phpIniValue(string $key, string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new BrokerException('Invalid php.ini value.', 2);
        }
        $ok = match ($key) {
            'memory_limit', 'upload_max_filesize', 'post_max_size' => (bool) preg_match('/^-?\d+[KMG]?$/i', $value),
            'max_execution_time', 'max_input_time', 'max_file_uploads' => (bool) preg_match('/^\d+$/', $value),
            'expose_php' => in_array(strtolower($value), ['on', 'off', '1', '0'], true),
            default => false,
        };
        if (!$ok) {
            throw new BrokerException('php.ini value failed validation.', 2);
        }
        return $value;
    }

    public static function password(string $password): string
    {
        if (strlen($password) < 16 || strlen($password) > 128) {
            throw new BrokerException('Password must be 16–128 characters.', 2);
        }
        if (str_contains($password, "\0") || str_contains($password, "\n")) {
            throw new BrokerException('Password contains invalid characters.', 2);
        }
        return $password;
    }

    public static function typedConfirm(string $got, string $expected): string
    {
        $got = trim($got);
        if ($got === '' || !hash_equals($expected, $got)) {
            throw new BrokerException('Confirmation phrase did not match.', 3);
        }
        return $got;
    }

    public static function objectKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 512 || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new BrokerException('Invalid object key.', 2);
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/=-]*$#', $key)) {
            throw new BrokerException('Invalid object key.', 2);
        }
        return $key;
    }

    public static function siteName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\0")) {
            throw new BrokerException('Invalid site name.', 2);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,190}$/', $name)) {
            throw new BrokerException('Invalid site name.', 2);
        }
        return $name;
    }

    public static function ipv4(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new BrokerException('Invalid IPv4 address.', 2);
        }
        return $ip;
    }

    public const ACCESS_MODES = ['localhost', 'specific', 'global'];

    public const GLOBAL_ACCESS_CONFIRM = 'OPEN-GLOBAL';

    /**
     * IPv4 or IPv4 CIDR. Rejects anything that is not a pure address/prefix
     * (SQL fragments, shell metacharacters, whitespace, quotes).
     */
    public static function ipOrCidr(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 43) {
            throw new BrokerException('Invalid IP or CIDR.', 2);
        }
        if (!preg_match('#^[0-9.]+(?:/[0-9]{1,2})?$#', $value)) {
            throw new BrokerException('Invalid IP or CIDR.', 2);
        }
        $parts = explode('/', $value, 2);
        $ip = self::ipv4($parts[0]);
        if (!isset($parts[1])) {
            return $ip;
        }
        if (!preg_match('/^\d{1,2}$/', $parts[1])) {
            throw new BrokerException('Invalid IP or CIDR.', 2);
        }
        $prefix = (int) $parts[1];
        if ($prefix < 0 || $prefix > 32) {
            throw new BrokerException('Invalid IP or CIDR prefix (0–32).', 2);
        }
        if ($prefix === 32) {
            return $ip;
        }

        return $ip . '/' . $prefix;
    }

    public static function accessMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::ACCESS_MODES, true)) {
            throw new BrokerException('Invalid access mode. Allowed: localhost, specific, global.', 2);
        }

        return $mode;
    }

    /**
     * @param  list<string>|string  $ips
     * @return list<string>
     */
    public static function accessIps(string $mode, array|string $ips): array
    {
        if (is_string($ips)) {
            $raw = preg_split('/\s*,\s*/', $ips, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $raw = $ips;
        }
        $normalized = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new BrokerException('Invalid IP or CIDR.', 2);
            }
            $normalized[] = self::ipOrCidr((string) $item);
        }
        $normalized = array_values(array_unique($normalized));
        if ($mode === 'localhost' || $mode === 'global') {
            if ($normalized !== []) {
                throw new BrokerException('IP list is only valid with --mode=specific.', 2);
            }

            return [];
        }
        if ($normalized === []) {
            throw new BrokerException('Specific-IP mode requires at least one IP or CIDR.', 2);
        }
        if (count($normalized) > 32) {
            throw new BrokerException('Too many IPs (maximum 32 per database).', 2);
        }
        foreach ($normalized as $cidr) {
            if ($cidr === '0.0.0.0' || $cidr === '0.0.0.0/0') {
                throw new BrokerException('0.0.0.0/0 is Global mode, not Specific IP. Use --mode=global with --confirm.', 2);
            }
        }

        return $normalized;
    }

    public static function searchNeedle(string $needle): string
    {
        $needle = trim($needle);
        if ($needle === '' || strlen($needle) > 200 || str_contains($needle, "\0") || str_contains($needle, "\n")) {
            throw new BrokerException('Invalid search string.', 2);
        }
        return $needle;
    }

    public static function cronLine(string $line): string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return $line;
        }
        if (str_contains($line, "\0") || str_contains($line, "\n") || str_contains($line, '`') || str_contains($line, '$(')) {
            throw new BrokerException('Cron line contains forbidden characters.', 2);
        }
        if (!preg_match('/^(@(reboot|yearly|annually|monthly|weekly|daily|hourly|midnight)\s+\S.*|\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S.*)$/', $line)) {
            throw new BrokerException('Cron line failed syntax validation.', 2);
        }
        return $line;
    }

    public static function normalizeAbsolute(string $path): string
    {
        $path = '/' . trim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }
}
