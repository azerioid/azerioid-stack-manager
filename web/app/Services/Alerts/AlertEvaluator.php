<?php

namespace App\Services\Alerts;

use App\Models\AlertIncident;
use App\Models\BackupJob;
use App\Models\Vhost;
use App\Models\Setting;
use App\Services\Broker\BrokerClient;
use Illuminate\Support\Carbon;

final class AlertEvaluator
{
    public function __construct(
        private readonly BrokerClient $broker,
        private readonly TelegramNotifier $telegram,
    ) {
    }

    /**
     * @return array{opened:int, resolved:int, notified:int}
     */
    public function run(): array
    {
        $problems = $this->collect();
        $openKeys = [];
        $opened = $resolved = $notified = 0;

        foreach ($problems as $p) {
            $key = $p['rule_key'].'|'.$p['subject'];
            $openKeys[$key] = true;
            $incident = AlertIncident::query()
                ->where('rule_key', $p['rule_key'])
                ->where('subject', $p['subject'])
                ->where('status', 'open')
                ->first();
            if ($incident) {
                continue;
            }
            $incident = AlertIncident::query()->create([
                'rule_key' => $p['rule_key'],
                'subject' => $p['subject'],
                'status' => 'open',
                'severity' => $p['severity'],
                'message' => $p['message'],
                'opened_at' => now(),
                'last_notified_at' => now(),
            ]);
            $opened++;
            if ($this->telegram->send('ALERT '.$p['subject']."\n".$p['message'])) {
                $notified++;
                $incident->forceFill(['last_notified_at' => now()])->save();
            }
        }

        $open = AlertIncident::query()->where('status', 'open')->get();
        foreach ($open as $incident) {
            $key = $incident->rule_key.'|'.$incident->subject;
            if (isset($openKeys[$key])) {
                continue;
            }
            $incident->forceFill([
                'status' => 'resolved',
                'resolved_at' => now(),
            ])->save();
            $resolved++;
            if ($this->telegram->send('RESOLVED '.$incident->subject."\n".$incident->message)) {
                $notified++;
            }
        }

        return ['opened' => $opened, 'resolved' => $resolved, 'notified' => $notified];
    }

    /** @return list<array{rule_key:string,subject:string,message:string,severity:string}> */
    public function collect(): array
    {
        $rules = Setting::get('alert.rules', []);
        if (! is_array($rules)) {
            $rules = [];
        }
        $disk = (int) ($rules['disk_percent'] ?? 85);
        $ram = (int) ($rules['ram_percent'] ?? 90);
        $load = (float) ($rules['load'] ?? 4.0);
        $tlsDays = (int) ($rules['tls_days'] ?? 14);
        // Creating a vhost before pointing DNS at it is the normal workflow, so a
        // freshly created site must not alarm immediately.
        $graceHours = (int) ($rules['tls_grace_hours'] ?? 24);
        $backupHours = (int) ($rules['backup_stale_hours'] ?? 36);
        $serviceDown = (bool) ($rules['service_down'] ?? true);
        $observedDown = (bool) ($rules['observed_down'] ?? true);
        $reboot = (bool) ($rules['reboot_required'] ?? true);
        $tlsOn = (bool) ($rules['tls'] ?? true);
        $backupOn = (bool) ($rules['backup_stale'] ?? true);
        // On by default: a scheduled job that fails silently is the whole reason its
        // exit code is recorded (A47).
        $cronOn = (bool) ($rules['cron_failed'] ?? true);
        $appDownOn = (bool) ($rules['app_down'] ?? true);

        $out = [];
        $status = $this->broker->call('status.all', [], [], null, false);
        if ($status->ok) {
            foreach (array_merge($status->data['controlled'] ?? [], $status->data['observed'] ?? []) as $svc) {
                $running = (bool) ($svc['running'] ?? false);
                $controllable = (bool) ($svc['controllable'] ?? false);
                if ($running) {
                    continue;
                }
                if ($controllable && ! $serviceDown) {
                    continue;
                }
                if (! $controllable && ! $observedDown) {
                    continue;
                }
                $unit = (string) ($svc['unit'] ?? 'unknown');
                $out[] = [
                    'rule_key' => 'service.down',
                    'subject' => $unit,
                    'message' => $unit.' is '.($svc['active_state'] ?? 'down'),
                    'severity' => $controllable ? 'high' : 'medium',
                ];
            }
        }

        $metrics = $this->broker->call('metrics.system', [], [], null, false);
        if ($metrics->ok) {
            $m = $metrics->data;
            $total = (int) ($m['memory']['total'] ?? 0);
            $used = (int) ($m['memory']['used'] ?? 0);
            if ($total > 0 && ($used / $total) * 100 >= $ram) {
                $pct = (int) round(($used / $total) * 100);
                $out[] = [
                    'rule_key' => 'resource.ram',
                    'subject' => 'memory',
                    'message' => "RAM usage {$pct}% (threshold {$ram}%)",
                    'severity' => 'high',
                ];
            }
            $load1 = (float) ($m['loadavg']['1'] ?? 0);
            if ($load1 >= $load) {
                $out[] = [
                    'rule_key' => 'resource.load',
                    'subject' => 'load',
                    'message' => "Load average {$load1} (threshold {$load})",
                    'severity' => 'medium',
                ];
            }
            foreach ($m['disks'] ?? [] as $d) {
                $pct = (int) rtrim((string) ($d['use_percent'] ?? '0'), '%');
                if ($pct >= $disk) {
                    $mount = (string) ($d['mount'] ?? '/');
                    $out[] = [
                        'rule_key' => 'resource.disk',
                        'subject' => $mount,
                        'message' => "Disk {$mount} at {$pct}% (threshold {$disk}%)",
                        'severity' => 'high',
                    ];
                }
            }
        }

        if ($reboot) {
            $rr = $this->broker->call('system.reboot-required', [], [], null, false);
            if ($rr->ok && ($rr->data['required'] ?? false)) {
                $pkgs = implode(', ', $rr->data['packages'] ?? []);
                $out[] = [
                    'rule_key' => 'system.reboot',
                    'subject' => 'reboot-required',
                    'message' => 'System restart required'.($pkgs !== '' ? ": {$pkgs}" : ''),
                    'severity' => 'medium',
                ];
            }
        }

        if ($tlsOn) {
            $tls = $this->broker->call('tls.certs', [], [], null, false);
            if ($tls->ok) {
                foreach ($tls->data['certs'] ?? [] as $c) {
                    $days = $c['days_remaining'] ?? null;
                    if ($days === null) {
                        continue;
                    }
                    if ((int) $days <= $tlsDays) {
                        $out[] = [
                            'rule_key' => 'tls.expiry',
                            'subject' => (string) $c['domain'],
                            'message' => ($c['domain'] ?? 'cert').' expires in '.(int) $days.' days',
                            'severity' => (int) $days < 0 ? 'high' : 'medium',
                        ];
                    }
                }
            }

            foreach ($this->tlsIssuanceFailures($graceHours) as $issue) {
                $out[] = $issue;
            }
        }

        if ($cronOn) {
            foreach ($this->cronFailures() as $issue) {
                $out[] = $issue;
            }
        }

        if ($appDownOn) {
            foreach ($this->appDownFailures() as $issue) {
                $out[] = $issue;
            }
        }

        if ($backupOn) {
            $last = BackupJob::query()->where('status', 'ok')->latest()->first();
            $stale = $last === null || $last->created_at->lt(Carbon::now()->subHours($backupHours));
            $failed = BackupJob::query()->where('status', 'failed')->where('created_at', '>=', now()->subHours($backupHours))->exists();
            if ($failed) {
                $out[] = [
                    'rule_key' => 'backup.failed',
                    'subject' => 'backup',
                    'message' => 'A backup job failed recently.',
                    'severity' => 'high',
                ];
            } elseif ($stale && BackupJob::query()->exists()) {
                $out[] = [
                    'rule_key' => 'backup.stale',
                    'subject' => 'backup',
                    'message' => "No successful backup in {$backupHours} hours.",
                    'severity' => 'medium',
                ];
            }
        }

        $auth = $this->broker->call('auth.audit', ['200'], [], null, false);
        if ($auth->ok && ($rules['ssh'] ?? true)) {
            $failedCount = (int) ($auth->data['failed_count'] ?? 0);
            if ($failedCount >= 12) {
                $out[] = [
                    'rule_key' => 'ssh.failures',
                    'subject' => 'sshd',
                    'message' => "{$failedCount} failed SSH logins in the recent auth log window.",
                    'severity' => 'medium',
                ];
            }
            foreach ($auth->data['new_root_ips'] ?? [] as $row) {
                $ip = (string) ($row['ip'] ?? '');
                if ($ip === '') {
                    continue;
                }
                $out[] = [
                    'rule_key' => 'ssh.newroot',
                    'subject' => $ip,
                    'message' => "Root SSH login from new source IP {$ip}",
                    'severity' => 'high',
                ];
            }
        }

        return $out;
    }

    /**
     * TLS configured but never actually issued (B1 / request #5).
     *
     * The expiry rule above reads `tls.certs`, which only lists certificates that
     * exist — so a vhost set to automatic TLS whose issuance has never succeeded
     * could not appear there at all, and the panel stayed silent about it. On the
     * verification host four vhosts were in exactly that state: configured for
     * automatic HTTPS, no certificate on disk, an unmatched-SNI handshake failure
     * for visitors, and no alert.
     *
     * vhost.list already computes this through CertProbe and AcmeStatusHint, which
     * also supplies the reason, so the alert can say *why* rather than just that
     * something is wrong.
     *
     * @return list<array{rule_key:string, subject:string, message:string, severity:string}>
     */
    /**
     * A scheduled job whose last recorded run exited non-zero (B2 / A47).
     *
     * Reads the exit code the wrapper records, not the job's output: "did it work" is a
     * status, and grepping output for the word error is how you get alerts that fire on
     * a log line containing the word error.
     *
     * A job that has never run raises nothing. That is the normal state of a job added a
     * minute ago, and a schedule the panel cannot evaluate — @monthly on the 1st — must
     * not be reported as broken for a month.
     *
     * Disabled jobs are skipped: the operator turned it off, and its last failure is
     * probably why.
     *
     * @return list<array<string,string>>
     */
    /**
     * A84: a vhost whose Octane/PM2/Docker worker is down. The site's runtime program
     * is cross-referenced against Supervisor's reported state; a persistent down state
     * (stopped/fatal/exited/backoff) alerts. Transient states (starting) and unknown
     * are left alone to avoid false alarms. FPM/static/proxy sites have no worker.
     *
     * @return list<array{rule_key:string, subject:string, message:string, severity:string}>
     */
    private function appDownFailures(): array
    {
        $vh = $this->broker->call('vhost.list', [], [], null, false);
        if (! $vh->ok) {
            return [];
        }
        $progs = $this->broker->call('supervisor.program.list', [], [], null, false);
        $state = [];
        if ($progs->ok) {
            foreach ($progs->data['programs'] ?? [] as $p) {
                if (is_array($p)) {
                    $state[(string) ($p['name'] ?? '')] = (string) ($p['status']['state'] ?? '');
                }
            }
        }

        $down = ['stopped', 'fatal', 'exited', 'backoff'];
        $out = [];
        foreach ($vh->data['vhosts'] ?? [] as $v) {
            if (! is_array($v) || ! empty($v['readonly'])) {
                continue;
            }
            $runtime = (string) ($v['runtime'] ?? 'fpm');
            $program = match ($runtime) {
                'octane' => (string) ($v['octane_program'] ?? ''),
                'pm2' => (string) ($v['pm2_program'] ?? ''),
                'docker' => (string) ($v['docker_program'] ?? ''),
                default => '',
            };
            if ($program === '') {
                continue;
            }
            $s = $state[$program] ?? 'unknown';
            if (! in_array($s, $down, true)) {
                continue;
            }
            $domain = (string) ($v['domain'] ?? '');
            $out[] = [
                'rule_key' => 'app.down',
                'subject' => $domain,
                'message' => $domain.' ('.$runtime.') worker is '.$s.' — the app is not running.',
                'severity' => 'high',
            ];
        }

        return $out;
    }

    private function cronFailures(): array
    {
        $res = $this->broker->call('cron.jobs', [], [], null, false);
        if (! $res->ok) {
            return [];
        }

        $out = [];
        foreach ((array) ($res->data['jobs'] ?? []) as $job) {
            if (! is_array($job) || ($job['enabled'] ?? true) !== true) {
                continue;
            }
            $last = is_array($job['last_run'] ?? null) ? $job['last_run'] : [];
            if (($last['ok'] ?? null) !== false) {
                continue;
            }
            $id = (string) ($job['id'] ?? '');
            $out[] = [
                // Keyed per job, so two failing jobs are two incidents and fixing one
                // resolves one — and a job that starts working again resolves itself.
                'rule_key' => 'cron.failed',
                'subject' => $id,
                'message' => 'Scheduled job ' . $id . ' (' . (string) ($job['owner'] ?? '') . ': '
                    . (string) ($job['command'] ?? '') . ') last exited '
                    . (string) ($last['exit_code'] ?? '?') . ' at ' . (string) ($last['at'] ?? 'an unknown time')
                    . '. Output is on the Scheduled jobs page.',
                'severity' => 'medium',
            ];
        }

        return $out;
    }

    private function tlsIssuanceFailures(int $graceHours): array
    {
        $res = $this->broker->call('vhost.list', [], [], null, false);
        if (! $res->ok) {
            return [];
        }

        $out = [];
        foreach ($res->data['vhosts'] ?? [] as $v) {
            if (! is_array($v) || ! empty($v['readonly'])) {
                continue;
            }
            $mode = (string) ($v['tls_mode'] ?? '');
            // Only modes that are *supposed* to obtain a certificate. `internal` and
            // `off` are working as configured.
            if (! in_array($mode, ['auto', 'dns01'], true)) {
                continue;
            }
            $status = is_array($v['tls_status'] ?? null) ? $v['tls_status'] : [];
            if (($status['ok'] ?? false) === true) {
                continue;
            }

            $domain = (string) ($v['domain'] ?? '');
            if ($domain === '' || $this->withinTlsGrace($domain, $graceHours)) {
                continue;
            }

            $failed = ($status['failed'] ?? false) === true;
            $reason = trim((string) ($status['error'] ?? ''));
            $out[] = [
                'rule_key' => 'tls.issuance',
                'subject' => $domain,
                'message' => $domain . ': TLS is set to ' . $mode
                    . ' but no certificate has been issued'
                    . ($reason !== '' ? ' — ' . $reason : '.'),
                'severity' => $failed ? 'high' : 'medium',
            ];
        }

        return $out;
    }

    /**
     * True while a vhost is still young enough that missing TLS is expected.
     *
     * Age comes from the A44 projection. When a vhost is not in it — an existing
     * host that has not run `azerioid vhost reconcile --repair` yet — we cannot
     * date it, and the bug being fixed here is *silence*, so it alerts rather than
     * skipping.
     */
    private function withinTlsGrace(string $domain, int $graceHours): bool
    {
        if ($graceHours <= 0) {
            return false;
        }
        $row = Vhost::query()->where('domain', $domain)->first();
        if ($row === null || $row->created_at === null) {
            return false;
        }

        return $row->created_at->gt(Carbon::now()->subHours($graceHours));
    }
}
