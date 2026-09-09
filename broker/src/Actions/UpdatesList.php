<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Lists pending OS package updates (apt or dnf). This is not a panel self-update.
 */
final class UpdatesList
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $os = OsRelease::detect($runtime);
        if ($os->pkgMgr === 'dnf') {
            return $this->listDnf($runtime, $os->distroKey);
        }

        return $this->listApt($runtime, $os->distroKey);
    }

    /** @return array{total:int,security:int,packages:list<array{name:string,security:bool,raw:string}>,source:string,pkg_mgr:string,distro:string,scope:string} */
    private function listApt(Runtime $runtime, string $distroKey): array
    {
        $security = 0;
        $total = 0;
        $fromNotifier = false;
        if ($runtime->fileExists('/usr/lib/update-notifier/apt-check')) {
            $check = $runtime->exec(['/usr/lib/update-notifier/apt-check'], null, 30);
            $raw = trim($check->stderr !== '' ? $check->stderr : $check->stdout);
            if (preg_match('/^(\d+);(\d+)$/', $raw, $m)) {
                $total = (int) $m[1];
                $security = (int) $m[2];
                $fromNotifier = true;
            }
        }

        $packages = [];
        $sim = $runtime->exec(['/usr/bin/apt-get', '-s', '-o', 'Debug::NoLocking=true', 'upgrade'], null, 60);
        foreach (explode("\n", $sim->stdout) as $line) {
            if (!preg_match('/^Inst\s+(\S+)\s+/', $line, $m)) {
                continue;
            }
            $isSecurity = str_contains(strtolower($line), '-security');
            $packages[] = [
                'name' => $m[1],
                'security' => $isSecurity,
                'raw' => $line,
            ];
        }
        if (!$fromNotifier) {
            $total = count($packages);
            $security = count(array_filter($packages, static fn ($p) => $p['security']));
        }

        return [
            'total' => $total,
            'security' => $security,
            'packages' => array_slice($packages, 0, 200),
            'source' => $fromNotifier ? 'apt-check' : 'apt-get -s',
            'pkg_mgr' => 'apt',
            'distro' => $distroKey,
            'scope' => 'os-packages',
        ];
    }

    /** @return array{total:int,security:int,packages:list<array{name:string,security:bool,raw:string}>,source:string,pkg_mgr:string,distro:string,scope:string} */
    private function listDnf(Runtime $runtime, string $distroKey): array
    {
        $bin = $runtime->fileExists('/usr/bin/dnf')
            ? '/usr/bin/dnf'
            : ($runtime->fileExists('/usr/bin/yum') ? '/usr/bin/yum' : '/usr/bin/dnf');

        // dnf check-update: exit 100 = updates available, 0 = none.
        $check = $runtime->exec([$bin, 'check-update', '--quiet'], null, 120);
        $packages = [];
        $securityNames = $this->dnfSecurityNames($runtime, $bin);

        foreach (explode("\n", $check->stdout . "\n" . $check->stderr) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Last metadata') || str_starts_with($line, 'Security:')) {
                continue;
            }
            // name.arch  version  repo
            if (!preg_match('/^(\S+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                continue;
            }
            $nevra = $m[1];
            $name = preg_replace('/\.[^.]+$/', '', $nevra) ?? $nevra;
            $isSecurity = isset($securityNames[$name]) || isset($securityNames[$nevra]);
            $packages[] = [
                'name' => $name,
                'security' => $isSecurity,
                'raw' => $line,
            ];
        }

        // Deduplicate by name (check-update can repeat arches).
        $byName = [];
        foreach ($packages as $p) {
            $existing = $byName[$p['name']] ?? null;
            if ($existing === null || ($p['security'] && !$existing['security'])) {
                $byName[$p['name']] = $p;
            }
        }
        $packages = array_values($byName);
        $security = count(array_filter($packages, static fn ($p) => $p['security']));

        return [
            'total' => count($packages),
            'security' => $security,
            'packages' => array_slice($packages, 0, 200),
            'source' => basename($bin) . ' check-update',
            'pkg_mgr' => 'dnf',
            'distro' => $distroKey,
            'scope' => 'os-packages',
        ];
    }

    /** @return array<string,true> */
    private function dnfSecurityNames(Runtime $runtime, string $bin): array
    {
        $names = [];
        if (!$runtime->fileExists($bin)) {
            return $names;
        }
        $info = $runtime->exec([$bin, 'updateinfo', 'list', 'security', '--quiet'], null, 60);
        foreach (explode("\n", $info->stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Typical: FEDORA-EPEL-2024-…  Critical/Important/…  pkg-1.2.3
            if (preg_match('/\s(\S+)\s*$/', $line, $m)) {
                $pkg = $m[1];
                $base = preg_replace('/-\d.*/', '', $pkg) ?? $pkg;
                $names[$pkg] = true;
                $names[$base] = true;
            }
        }
        return $names;
    }
}
