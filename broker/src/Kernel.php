<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker;

use AzerioidPanel\Broker\Actions\AuthAudit;
use AzerioidPanel\Broker\Actions\ComponentAdopt;
use AzerioidPanel\Broker\Actions\ComponentInstall;
use AzerioidPanel\Broker\Actions\ComponentList;
use AzerioidPanel\Broker\Actions\ComponentOperationLog;
use AzerioidPanel\Broker\Actions\ComponentPreflightAction;
use AzerioidPanel\Broker\Actions\ComponentStatus;
use AzerioidPanel\Broker\Actions\ComponentUninstall;
use AzerioidPanel\Broker\Actions\CaddyApplyConfig;
use AzerioidPanel\Broker\Actions\BackupList;
use AzerioidPanel\Broker\Actions\BackupPrune;
use AzerioidPanel\Broker\Actions\BackupRestore;
use AzerioidPanel\Broker\Actions\BackupRun;
use AzerioidPanel\Broker\Actions\CronManage;
use AzerioidPanel\Broker\Actions\DbAccessSet;
use AzerioidPanel\Broker\Actions\DbAccessShow;
use AzerioidPanel\Broker\Actions\DbAdd;
use AzerioidPanel\Broker\Actions\DbDel;
use AzerioidPanel\Broker\Actions\DbDump;
use AzerioidPanel\Broker\Actions\DbEngine;
use AzerioidPanel\Broker\Actions\DbList;
use AzerioidPanel\Broker\Actions\DbResetpw;
use AzerioidPanel\Broker\Actions\Fail2banInstall;
use AzerioidPanel\Broker\Actions\FirewallStatus;
use AzerioidPanel\Broker\Actions\FirewallUnban;
use AzerioidPanel\Broker\Actions\LogsSearch;
use AzerioidPanel\Broker\Actions\LogsTail;
use AzerioidPanel\Broker\Actions\MariadbBindFix;
use AzerioidPanel\Broker\Actions\MariadbBindRollback;
use AzerioidPanel\Broker\Actions\MariadbBindStatus;
use AzerioidPanel\Broker\Actions\MetricsSystem;
use AzerioidPanel\Broker\Actions\PanelDomainSet;
use AzerioidPanel\Broker\Actions\PanelRuntime;
use AzerioidPanel\Broker\Actions\PhpIniGet;
use AzerioidPanel\Broker\Actions\PhpIniSet;
use AzerioidPanel\Broker\Actions\PhpOpcache;
use AzerioidPanel\Broker\Actions\PhpTimeoutsEnsure;
use AzerioidPanel\Broker\Actions\PhpVersions;
use AzerioidPanel\Broker\Actions\SchedulerInstall;
use AzerioidPanel\Broker\Actions\ServiceControl;
use AzerioidPanel\Broker\Actions\ServiceStatus;
use AzerioidPanel\Broker\Actions\SpacesTest;
use AzerioidPanel\Broker\Actions\StatusAll;
use AzerioidPanel\Broker\Actions\SupervisorProgram;
use AzerioidPanel\Broker\Actions\SystemReboot;
use AzerioidPanel\Broker\Actions\SystemRebootRequired;
use AzerioidPanel\Broker\Actions\TerminalSession;
use AzerioidPanel\Broker\Actions\TlsCerts;
use AzerioidPanel\Broker\Actions\TlsDnsCredential;
use AzerioidPanel\Broker\Actions\TlsRenew;
use AzerioidPanel\Broker\Actions\UpdatesApply;
use AzerioidPanel\Broker\Actions\UpdatesList;
use AzerioidPanel\Broker\Actions\VersionAll;
use AzerioidPanel\Broker\Actions\VhostAdd;
use AzerioidPanel\Broker\Actions\VhostDel;
use AzerioidPanel\Broker\Actions\VhostEdit;
use AzerioidPanel\Broker\Actions\VhostFilesAction;
use AzerioidPanel\Broker\Actions\VhostList;
use AzerioidPanel\Broker\Actions\WebFrontRouterMigrate;
use AzerioidPanel\Broker\Actions\WebReleaseSitePorts;

final class Kernel
{
    /** @var array<string, class-string> */
    public const ACTIONS = [
        'status.all' => StatusAll::class,
        'panel.runtime' => PanelRuntime::class,
        'panel.domain.set' => PanelDomainSet::class,
        'panel.domain.show' => PanelDomainSet::class,
        'component.list' => ComponentList::class,
        'component.status' => ComponentStatus::class,
        'component.preflight' => ComponentPreflightAction::class,
        'component.install' => ComponentInstall::class,
        'component.adopt' => ComponentAdopt::class,
        'component.uninstall' => ComponentUninstall::class,
        'component.operation.log' => ComponentOperationLog::class,
        'version.all' => VersionAll::class,
        'metrics.system' => MetricsSystem::class,
        'service.status' => ServiceStatus::class,
        'service.start' => ServiceControl::class,
        'service.stop' => ServiceControl::class,
        'service.restart' => ServiceControl::class,
        'vhost.list' => VhostList::class,
        'vhost.add' => VhostAdd::class,
        'vhost.edit' => VhostEdit::class,
        'vhost.del' => VhostDel::class,
        'caddy.apply' => CaddyApplyConfig::class,
        'web.reload' => CaddyApplyConfig::class,
        'web.release-site-ports' => WebReleaseSitePorts::class,
        'web.front-router.migrate' => WebFrontRouterMigrate::class,
        'db.list' => DbList::class,
        'db.add' => DbAdd::class,
        'db.del' => DbDel::class,
        'db.resetpw' => DbResetpw::class,
        'db.engine' => DbEngine::class,
        'db.dump' => DbDump::class,
        'db.access.show' => DbAccessShow::class,
        'db.access.set' => DbAccessSet::class,
        'logs.tail' => LogsTail::class,
        'logs.search' => LogsSearch::class,
        'php.versions' => PhpVersions::class,
        'php.timeouts.ensure' => PhpTimeoutsEnsure::class,
        'php.ini.get' => PhpIniGet::class,
        'php.ini.set' => PhpIniSet::class,
        'php.opcache.stats' => PhpOpcache::class,
        'php.opcache.reset' => PhpOpcache::class,
        'mariadb.bind.status' => MariadbBindStatus::class,
        'mariadb.bind.fix' => MariadbBindFix::class,
        'mariadb.bind.rollback' => MariadbBindRollback::class,
        'system.reboot-required' => SystemRebootRequired::class,
        'system.reboot' => SystemReboot::class,
        'scheduler.install' => SchedulerInstall::class,
        'updates.list' => UpdatesList::class,
        'updates.apply.security' => UpdatesApply::class,
        'updates.apply.all' => UpdatesApply::class,
        'tls.certs' => TlsCerts::class,
        'tls.dns-providers' => TlsDnsCredential::class,
        'tls.dns-credential.store' => TlsDnsCredential::class,
        'tls.dns-credential.status' => TlsDnsCredential::class,
        'tls.renew.dry-run' => TlsRenew::class,
        'tls.renew.hook-install' => TlsRenew::class,
        'backup.db' => BackupRun::class,
        'backup.files' => BackupRun::class,
        'backup.caddy' => BackupRun::class,
        'backup.list' => BackupList::class,
        'backup.prune' => BackupPrune::class,
        'backup.restore.db' => BackupRestore::class,
        'backup.restore.files' => BackupRestore::class,
        'spaces.test' => SpacesTest::class,
        'auth.audit' => AuthAudit::class,
        'firewall.status' => FirewallStatus::class,
        'firewall.unban' => FirewallUnban::class,
        'firewall.fail2ban.install' => Fail2banInstall::class,
        'cron.list' => CronManage::class,
        'cron.set' => CronManage::class,
        'supervisor.program.list' => SupervisorProgram::class,
        'supervisor.program.create' => SupervisorProgram::class,
        'supervisor.program.update' => SupervisorProgram::class,
        'supervisor.program.delete' => SupervisorProgram::class,
        'supervisor.program.status' => SupervisorProgram::class,
        'supervisor.program.start' => SupervisorProgram::class,
        'supervisor.program.stop' => SupervisorProgram::class,
        'supervisor.program.restart' => SupervisorProgram::class,
        'supervisor.program.logs' => SupervisorProgram::class,
        'terminal.session.start' => TerminalSession::class,
        'terminal.session.stop' => TerminalSession::class,
        'terminal.session.heartbeat' => TerminalSession::class,
        'terminal.session.list' => TerminalSession::class,
        'terminal.session.status' => TerminalSession::class,
        'terminal.session.cleanup' => TerminalSession::class,
        'vhost.files.list' => VhostFilesAction::class,
        'vhost.files.read' => VhostFilesAction::class,
        'vhost.files.write' => VhostFilesAction::class,
        'vhost.files.mkdir' => VhostFilesAction::class,
        'vhost.files.rename' => VhostFilesAction::class,
        'vhost.files.move' => VhostFilesAction::class,
        'vhost.files.delete' => VhostFilesAction::class,
    ];

    public function __construct(
        private readonly Config $config,
        private Runtime $runtime,
    ) {
        $this->runtime = $this->config->runtimeWithDb($this->runtime);
    }

    /**
     * @param  array<int,string>  $argv
     * @param  array<string,mixed>|null  $stdin  Pre-parsed JSON. When null, read STDIN.
     */
    public function run(array $argv, ?array $stdin = null): int
    {
        $action = $argv[1] ?? '';
        $args = array_values(array_slice($argv, 2));
        $input = $stdin ?? $this->readStdinJson();

        try {
            $action = Validator::action($action);
            if (!isset(self::ACTIONS[$action])) {
                throw new BrokerException('Unknown action.', 2);
            }
            $class = self::ACTIONS[$action];
            $handler = new $class();
            $data = $handler->handle($action, $args, $input, $this->runtime, $this->config);
            $this->audit($action, array_merge($args, $input), true, 0, null);
            $this->emit(true, $data, null, 0);
            return 0;
        } catch (BrokerException $e) {
            $this->audit($action !== '' ? $action : 'invalid', array_merge($args, $input), false, $e->errorCode, $e->getMessage());
            $this->emit(false, null, $e->getMessage(), $e->errorCode);
            return $e->errorCode;
        } catch (\Throwable $e) {
            $safe = self::publicError($e);
            fwrite(STDERR, 'broker: ' . $e::class . "\n");
            $this->audit($action !== '' ? $action : 'crash', array_merge($args, $input), false, 1, $safe);
            $this->emit(false, null, $safe, 1);
            return 1;
        }
    }

    /** @return array<string,mixed> */
    public static function decodeStdinString(string $raw): array
    {
        $trim = trim($raw);
        if ($trim === '') {
            return [];
        }
        $decoded = json_decode($trim, true);
        if (is_array($decoded) && str_starts_with($trim, '{')) {
            return $decoded;
        }
        // Accidental pipes (installer heredocs, `ssh bash -s`) are not JSON objects.
        if (! str_starts_with($trim, '{')) {
            return [];
        }

        throw new BrokerException('Stdin must be a JSON object when provided.', 2);
    }

    /** @return array<string,mixed> */
    private function readStdinJson(): array
    {
        if (!defined('STDIN') || !is_resource(STDIN)) {
            return [];
        }
        $raw = stream_get_contents(STDIN);
        if ($raw === false) {
            return [];
        }

        return self::decodeStdinString($raw);
    }

    private function audit(string $action, array $args, bool $ok, int $code, ?string $error): void
    {
        try {
            (new AuditLog($this->config, $this->runtime))->write($action, $args, $ok, $code, $error);
        } catch (\Throwable) {
            fwrite(STDERR, "broker: failed to write audit log\n");
        }
    }

    private function emit(bool $ok, mixed $data, ?string $error, int $code): void
    {
        echo json_encode([
            'ok' => $ok,
            'data' => $data,
            'error' => $error,
            'code' => $code,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function publicError(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            return PosixRuntime::describePdo($e);
        }
        $msg = $e->getMessage();
        if (preg_match('/IDENTIFIED BY|passwd\\s*=|password\\s*=/i', $msg) === 1) {
            return $e::class . ' during broker action (details redacted).';
        }
        $msg = trim($msg);
        return $msg !== '' ? $e::class . ': ' . $msg : $e::class . ' during broker action.';
    }
}
