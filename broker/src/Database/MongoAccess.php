<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

/**
 * MongoDB has no per-database host authentication. Remote access is
 * instance-wide (bindIp + firewall on 27017) and is applied by DbAccessApply.
 */
final class MongoAccess
{
    /**
     * @param  list<string>  $ips
     * @return array{enforcement:string,note:string}
     */
    public function apply(string $dbName, string $mode, array $ips): array
    {
        return [
            'enforcement' => 'instance_firewall',
            'note' => 'MongoDB cannot restrict hosts per database. Requested mode=' . $mode
                . ($ips !== [] ? ' ips=' . implode(',', $ips) : '')
                . ' is stored for ' . $dbName
                . '; network enforcement is the instance-wide union on port 27017.',
        ];
    }
}
