<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Validator;

final class DbAccessPolicy
{
    public const PORTS = [
        'mariadb' => 3306,
        'postgresql' => 5432,
        'mongodb' => 27017,
    ];

    public const ENFORCEMENT = [
        'mariadb' => 'engine_host',
        'postgresql' => 'engine_hba',
        'mongodb' => 'instance_firewall',
    ];

    public static function port(string $engine): int
    {
        if (!isset(self::PORTS[$engine])) {
            throw new BrokerException('Unknown database engine.', 2);
        }

        return self::PORTS[$engine];
    }

    /**
     * @param  list<array{mode:string,ips?:list<string>}>  $entries
     * @return array{network:string,ips:list<string>,bind_public:bool}
     */
    public static function aggregate(array $entries): array
    {
        $ips = [];
        $global = false;
        foreach ($entries as $entry) {
            $mode = (string) ($entry['mode'] ?? 'localhost');
            if ($mode === 'global') {
                $global = true;
            }
            if ($mode === 'specific') {
                foreach ((array) ($entry['ips'] ?? []) as $ip) {
                    if (is_string($ip) && $ip !== '') {
                        $ips[$ip] = true;
                    }
                }
            }
        }
        if ($global) {
            return ['network' => 'global', 'ips' => [], 'bind_public' => true];
        }
        if ($ips !== []) {
            $list = array_keys($ips);
            sort($list);

            return ['network' => 'specific', 'ips' => $list, 'bind_public' => true];
        }

        return ['network' => 'localhost', 'ips' => [], 'bind_public' => false];
    }

    /**
     * @param  array{mode:string,ips:list<string>}  $requested
     * @param  array{network:string,ips:list<string>,bind_public:bool}  $instance
     */
    public static function mongoConflict(array $requested, array $instance): bool
    {
        $want = $requested['mode'];
        $have = $instance['network'];
        if ($want === 'localhost') {
            return $have !== 'localhost';
        }
        if ($want === 'global') {
            return $have !== 'global';
        }
        if ($have === 'global') {
            return true;
        }
        if ($have !== 'specific') {
            return false;
        }
        $wantIps = $requested['ips'];
        $haveIps = $instance['ips'];
        sort($wantIps);
        sort($haveIps);

        return $wantIps !== $haveIps;
    }

    /**
     * @param  list<string>  $cidrs
     * @return list<string>
     */
    public static function mariadbHosts(array $cidrs): array
    {
        $hosts = [];
        foreach ($cidrs as $cidr) {
            $hosts[] = self::mariadbHost($cidr);
        }

        return array_values(array_unique($hosts));
    }

    public static function mariadbHost(string $cidr): string
    {
        $cidr = Validator::ipOrCidr($cidr);
        if (!str_contains($cidr, '/')) {
            return $cidr;
        }
        [$ip, $prefixStr] = explode('/', $cidr, 2);
        $prefix = (int) $prefixStr;
        $octets = explode('.', $ip);
        $host = match ($prefix) {
            32 => $ip,
            24 => $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.%',
            16 => $octets[0] . '.' . $octets[1] . '.%.%',
            8 => $octets[0] . '.%.%.%',
            0 => '%',
            default => null,
        };
        if ($host === null) {
            throw new BrokerException(
                'MariaDB host grants support CIDR prefixes /8, /16, /24, and /32 only. Use one of those, or switch to PostgreSQL for arbitrary prefixes.',
                2
            );
        }

        return SqlIdent::mysqlHost($host);
    }

    public static function pgCidr(string $cidr): string
    {
        $cidr = Validator::ipOrCidr($cidr);
        if (!str_contains($cidr, '/')) {
            return $cidr . '/32';
        }

        return $cidr;
    }

    /**
     * @return array{mode:string,ips:list<string>,label:string,enforcement:string,enforcement_label:string,caveat:?string,instance_effective:array{network:string,ips:list<string>,bind_public:bool},conflict:bool}
     */
    public static function describe(string $engine, array $requested, array $instance): array
    {
        $mode = (string) ($requested['mode'] ?? 'localhost');
        $ips = (array) ($requested['ips'] ?? []);
        $enforcement = self::ENFORCEMENT[$engine] ?? 'unknown';
        $caveat = null;
        $conflict = false;
        if ($engine === 'mongodb') {
            $caveat = 'MongoDB has no per-database host authentication. Specific IP and Global modes only change firewall/bind for this instance’s port 27017 — every database on this mongod shares that network scope.';
            $conflict = self::mongoConflict(['mode' => $mode, 'ips' => $ips], $instance);
            if ($conflict) {
                $caveat .= ' This database’s requested scope is narrower than the instance-wide firewall already required by another database.';
            }
        }

        return [
            'mode' => $mode,
            'ips' => $ips,
            'label' => self::modeLabel($mode, $ips),
            'enforcement' => $enforcement,
            'enforcement_label' => match ($enforcement) {
                'engine_host' => 'MariaDB user@host grants (per database)',
                'engine_hba' => 'PostgreSQL pg_hba.conf (per database)',
                'instance_firewall' => 'Firewall on port 27017 (instance-wide, not per database)',
                default => $enforcement,
            },
            'caveat' => $caveat,
            'instance_effective' => $instance,
            'conflict' => $conflict,
        ];
    }

    /** @param  list<string>  $ips */
    public static function modeLabel(string $mode, array $ips = []): string
    {
        return match ($mode) {
            'specific' => 'Specific IPs' . ($ips !== [] ? ' (' . implode(', ', $ips) . ')' : ''),
            'global' => 'Global (any IP)',
            default => 'Localhost only',
        };
    }
}
