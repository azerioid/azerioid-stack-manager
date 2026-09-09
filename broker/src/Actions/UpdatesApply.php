<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Actions;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

/**
 * Applies OS package updates (apt or dnf). Not a panel self-update.
 */
final class UpdatesApply
{
    public function handle(string $action, array $args, array $input, Runtime $runtime, Config $config): array
    {
        $os = OsRelease::detect($runtime);
        if ($action === 'updates.apply.security') {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'APPLY-SECURITY');
            $result = $os->pkgMgr === 'dnf'
                ? $this->applyDnfSecurity($runtime)
                : $this->applyAptSecurity($runtime);
        } else {
            Validator::typedConfirm((string) ($input['confirm'] ?? ''), 'APPLY-ALL');
            $result = $os->pkgMgr === 'dnf'
                ? $this->applyDnfAll($runtime)
                : $this->applyAptAll($runtime);
        }

        $out = trim($result->stdout . "\n" . $result->stderr);
        if (strlen($out) > 200_000) {
            $out = substr($out, 0, 200_000) . "\n… truncated …";
        }
        if (!$result->ok()) {
            throw new BrokerException($out !== '' ? $out : 'Update command failed.', 1);
        }
        return [
            'action' => $action,
            'exit' => $result->exit,
            'output' => $out,
            'pkg_mgr' => $os->pkgMgr,
            'scope' => 'os-packages',
        ];
    }

    private function applyAptSecurity(Runtime $runtime): \AzerioidPanel\Broker\ExecResult
    {
        if (!$runtime->fileExists('/usr/sbin/unattended-upgrade') && !$runtime->fileExists('/usr/bin/unattended-upgrade')) {
            throw new BrokerException('unattended-upgrade is not installed.', 3);
        }
        $bin = $runtime->fileExists('/usr/sbin/unattended-upgrade')
            ? '/usr/sbin/unattended-upgrade'
            : '/usr/bin/unattended-upgrade';
        return $runtime->exec([$bin, '-v'], null, 900);
    }

    private function applyAptAll(Runtime $runtime): \AzerioidPanel\Broker\ExecResult
    {
        return $runtime->exec([
            '/usr/bin/apt-get',
            '-y',
            '-o',
            'Dpkg::Options::=--force-confold',
            'upgrade',
        ], null, 900);
    }

    private function dnfBin(Runtime $runtime): string
    {
        if ($runtime->fileExists('/usr/bin/dnf')) {
            return '/usr/bin/dnf';
        }
        if ($runtime->fileExists('/usr/bin/yum')) {
            return '/usr/bin/yum';
        }
        throw new BrokerException('Neither dnf nor yum is available.', 3);
    }

    private function applyDnfSecurity(Runtime $runtime): \AzerioidPanel\Broker\ExecResult
    {
        $bin = $this->dnfBin($runtime);
        return $runtime->exec([$bin, '-y', 'update', '--security'], null, 900);
    }

    private function applyDnfAll(Runtime $runtime): \AzerioidPanel\Broker\ExecResult
    {
        $bin = $this->dnfBin($runtime);
        return $runtime->exec([$bin, '-y', 'upgrade'], null, 900);
    }
}
