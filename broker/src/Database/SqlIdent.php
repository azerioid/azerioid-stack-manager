<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Validator;

final class SqlIdent
{
    public static function mysql(string $name): string
    {
        if (!preg_match(Validator::DB_NAME_PATTERN, $name) && $name !== 'localhost' && $name !== '127.0.0.1') {
            throw new BrokerException('Unsafe SQL identifier.', 2);
        }

        return $name;
    }

    /**
     * MariaDB user host: localhost, loopback, %, IPv4, or MariaDB % wildcards.
     */
    public static function mysqlHost(string $host): string
    {
        $host = trim($host);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '%') {
            return $host;
        }
        if (preg_match('/^(?:(?:\d{1,3}|%)\.){3}(?:\d{1,3}|%)$/', $host) !== 1) {
            throw new BrokerException('Unsafe SQL identifier.', 2);
        }
        foreach (explode('.', $host) as $octet) {
            if ($octet === '%') {
                continue;
            }
            $n = (int) $octet;
            if ((string) $n !== $octet || $n < 0 || $n > 255) {
                throw new BrokerException('Unsafe SQL identifier.', 2);
            }
        }

        return $host;
    }

    public static function postgres(string $name): string
    {
        if (!preg_match(Validator::DB_NAME_PATTERN, $name)) {
            throw new BrokerException('Unsafe SQL identifier.', 2);
        }

        return $name;
    }

    public static function escapeLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
