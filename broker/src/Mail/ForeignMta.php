<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Component\ManagedManifest;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Component\PackageQuery;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Detects a mail-transport agent this panel does not manage.
 *
 * A36 §9.8: replacing one is ALWAYS gated behind typed REPLACE-MTA, including
 * Debian's stock Exim — there is no auto-replace convenience exception.
 *
 * Detection must stay quiet on a host with no MTA at all: an empty package
 * database, a `not-found` unit, and no listener on :25 all mean "nothing here",
 * never "something foreign".
 */
final class ForeignMta
{
    /** @var list<string> */
    public const FALLBACK_PACKAGES = [
        'exim4',
        'exim4-base',
        'exim4-daemon-light',
        'exim4-daemon-heavy',
        'exim',
        'sendmail',
        'sendmail-bin',
        'nullmailer',
        'msmtp-mta',
    ];

    /** @var list<string> */
    private const FOREIGN_UNITS = ['exim4', 'exim', 'sendmail', 'nullmailer', 'opensmtpd'];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        private readonly OsRelease $os,
    ) {
    }

    /**
     * @return array{present:bool,packages:list<string>,units:list<string>,postfix_foreign:bool,reasons:list<string>}
     */
    public function detect(): array
    {
        $paths = MailPaths::for($this->runtime, $this->config, $this->os);
        $packages = $this->installedForeignPackages($paths->foreignMtaPackages());
        $units = $this->activeForeignUnits();
        $postfixForeign = $this->unmanagedPostfixPresent();

        $reasons = [];
        if ($packages !== []) {
            $reasons[] = 'Installed MTA package(s): ' . implode(', ', $packages) . '.';
        }
        foreach ($units as $unit) {
            $reasons[] = "Running mail service {$unit}.";
        }
        if ($postfixForeign) {
            $reasons[] = 'Postfix is already installed on this host but was not installed by the panel.';
        }

        return [
            'present' => $reasons !== [],
            'packages' => $packages,
            'units' => $units,
            'postfix_foreign' => $postfixForeign,
            'reasons' => $reasons,
        ];
    }

    /**
     * Packages to remove before installing the panel mail stack. Postfix is never
     * listed: apt/dnf upgrade an existing Postfix in place rather than replacing it.
     *
     * @return list<string>
     */
    public function packagesToRemove(): array
    {
        $paths = MailPaths::for($this->runtime, $this->config, $this->os);

        return $this->installedForeignPackages($paths->foreignMtaPackages());
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function installedForeignPackages(array $candidates): array
    {
        $found = [];
        foreach ($candidates as $package) {
            $package = trim($package);
            if ($package === '' || $package === 'postfix') {
                continue;
            }
            if (PackageQuery::isInstalled($this->runtime, $package, $this->os->pkgMgr)) {
                $found[] = $package;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function activeForeignUnits(): array
    {
        $active = [];
        foreach (self::FOREIGN_UNITS as $unit) {
            $result = $this->runtime->exec(['/usr/bin/systemctl', 'is-active', $unit], null, 15);
            if (trim($result->stdout) === 'active') {
                $active[] = $unit;
            }
        }

        return $active;
    }

    /**
     * Postfix present on disk but absent from the managed manifest = adopted-or-foreign.
     * Once the panel records `mail`, its own Postfix is never treated as foreign.
     */
    private function unmanagedPostfixPresent(): bool
    {
        $managed = ManagedManifest::load($this->runtime, $this->config->managedComponentsPath);
        if ($managed->has('mail')) {
            return false;
        }

        return PackageQuery::isInstalled($this->runtime, 'postfix', $this->os->pkgMgr);
    }
}
