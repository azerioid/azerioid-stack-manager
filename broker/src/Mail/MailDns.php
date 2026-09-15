<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Runtime;

/**
 * DNS guidance for an enabled mail domain. The panel does not own the operator's
 * DNS provider (A36 §3.3) — it renders exact records to copy, then verifies them
 * with live lookups so the checklist can go green on its own.
 *
 * SPF is mode-aware: a smarthost sends from the provider's IP range, so quoting
 * this host's IP would authorize the wrong sender and silently fail alignment.
 */
final class MailDns
{
    /** Relay hostname suffix => published SPF include for well-known providers. */
    private const PROVIDER_SPF_INCLUDES = [
        'amazonaws.com' => 'amazonses.com',
        'sendgrid.net' => 'sendgrid.net',
        'mailgun.org' => 'mailgun.org',
        'mailgun.com' => 'mailgun.org',
        'postmarkapp.com' => 'spf.mtasv.net',
        'sparkpostmail.com' => 'sparkpostmail.com',
        'mandrillapp.com' => 'spf.mandrillapp.com',
        'brevo.com' => 'spf.brevo.com',
        'sendinblue.com' => 'spf.sendinblue.com',
    ];

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * SPF text for the active outbound path.
     *
     * @param array<string,mixed>|null $smarthost
     */
    public static function spf(string $mailHostname, string $serverIp, ?array $smarthost): string
    {
        if ($smarthost !== null && trim((string) ($smarthost['host'] ?? '')) !== '') {
            $include = self::providerInclude((string) $smarthost['host']);

            return "v=spf1 include:{$include} ~all";
        }

        $mechanisms = ['v=spf1'];
        if ($mailHostname !== '') {
            $mechanisms[] = 'a:' . $mailHostname;
        }
        if ($serverIp !== '') {
            $mechanisms[] = 'ip4:' . $serverIp;
        }
        $mechanisms[] = '~all';

        return implode(' ', $mechanisms);
    }

    /** Relay providers publish their own SPF; a generic relay is authorized by its own hostname. */
    public static function providerInclude(string $relayHost): string
    {
        $relayHost = strtolower(trim($relayHost));
        foreach (self::PROVIDER_SPF_INCLUDES as $suffix => $include) {
            if ($relayHost === $suffix || str_ends_with($relayHost, '.' . $suffix)) {
                return $include;
            }
        }

        return $relayHost;
    }

    public static function dmarc(string $domain): string
    {
        return "v=DMARC1; p=none; rua=mailto:postmaster@{$domain}; fo=1";
    }

    /**
     * @param array<string,mixed>|null $smarthost
     * @return list<array{type:string,name:string,value:string,purpose:string,required:bool}>
     */
    public static function records(
        string $domain,
        string $mailHostname,
        string $serverIp,
        ?array $smarthost,
        string $dkimSelector,
        string $dkimValue,
    ): array {
        $records = [
            [
                'type' => 'MX',
                'name' => $domain,
                'value' => '10 ' . $mailHostname,
                'purpose' => 'Route inbound mail for this domain to your mail host.',
                'required' => true,
            ],
            [
                'type' => 'A',
                'name' => $mailHostname,
                'value' => $serverIp !== '' ? $serverIp : '<this server IPv4>',
                'purpose' => 'Resolve the mail hostname to this server.',
                'required' => true,
            ],
            [
                'type' => 'TXT',
                'name' => $domain,
                'value' => self::spf($mailHostname, $serverIp, $smarthost),
                'purpose' => $smarthost !== null
                    ? 'SPF — authorizes your relay provider to send as this domain.'
                    : 'SPF — authorizes this server to send as this domain.',
                'required' => true,
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc.' . $domain,
                'value' => self::dmarc($domain),
                'purpose' => 'DMARC — start at p=none, raise to quarantine/reject once SPF and DKIM align.',
                'required' => true,
            ],
        ];

        if ($dkimValue !== '') {
            $records[] = [
                'type' => 'TXT',
                'name' => $dkimSelector . '._domainkey.' . $domain,
                'value' => $dkimValue,
                'purpose' => 'DKIM — public key for the signature this server adds to outbound mail.',
                'required' => true,
            ];
        }

        $records[] = [
            'type' => 'PTR',
            'name' => $serverIp !== '' ? $serverIp : '<this server IPv4>',
            'value' => $mailHostname,
            'purpose' => 'Reverse DNS — set this at your VPS provider, not in your DNS zone. '
                . 'Receivers reject mail from hosts whose PTR does not match.',
            'required' => true,
        ];

        return $records;
    }

    /**
     * Live lookups so the checklist shows pass/fail instead of "copy this and hope".
     *
     * @param  list<array{type:string,name:string,value:string,purpose:string,required:bool}>  $records
     * @return list<array<string,mixed>>
     */
    public function verify(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $out[] = $record + $this->checkRecord($record);
        }

        return $out;
    }

    /** @param array{type:string,name:string,value:string} $record */
    private function checkRecord(array $record): array
    {
        if ($record['type'] === 'PTR') {
            return ['state' => 'unknown', 'observed' => '', 'note' => 'Set reverse DNS at your VPS provider.'];
        }
        $observed = $this->lookup($record['name'], $record['type']);
        if ($observed === []) {
            return ['state' => 'missing', 'observed' => '', 'note' => 'No matching record found in public DNS.'];
        }
        $needle = $this->comparable($record['type'], $record['value']);
        foreach ($observed as $candidate) {
            if ($this->comparable($record['type'], $candidate) === $needle) {
                return ['state' => 'ok', 'observed' => $candidate, 'note' => ''];
            }
        }
        if ($record['type'] === 'TXT' && str_starts_with($needle, 'v=spf1')) {
            foreach ($observed as $candidate) {
                if (str_starts_with($this->comparable('TXT', $candidate), 'v=spf1')) {
                    return [
                        'state' => 'mismatch',
                        'observed' => $candidate,
                        'note' => 'An SPF record exists but does not match the suggested value for your current outbound mode.',
                    ];
                }
            }
        }

        return [
            'state' => 'mismatch',
            'observed' => $observed[0],
            'note' => 'A record exists but its value differs from the suggestion.',
        ];
    }

    /** @return list<string> */
    private function lookup(string $name, string $type): array
    {
        // Query a public resolver explicitly. Hosts using systemd-resolved (127.0.0.53)
        // intermittently return empty answers for apex MX/TXT even when the records
        // are live at 1.1.1.1 / the authoritative NS — which the checklist then
        // falsely paints as "missing" while A/DMARC on the same zone look fine.
        $stdout = '';
        foreach (['1.1.1.1', '8.8.8.8'] as $resolver) {
            $result = $this->runtime->exec(
                ['/usr/bin/dig', '+short', '@' . $resolver, $type, $name],
                null,
                15
            );
            if ($result->ok() && trim($result->stdout) !== '') {
                $stdout = trim($result->stdout);
                break;
            }
        }
        if ($stdout === '') {
            return [];
        }

        // dig +short may emit multi-string TXT answers either as one line
        // (`"aaa" "bbb"`) or as one quoted chunk per line. Concatenate lines so
        // comparable() can join the segments before matching the suggestion.
        if (strtoupper($type) === 'TXT') {
            $joined = [];
            foreach (explode("\n", $stdout) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $joined[] = $line;
                }
            }

            return $joined === [] ? [] : [implode(' ', $joined)];
        }

        $values = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $values[] = $line;
            }
        }

        return $values;
    }

    /**
     * Normalize dig answers for comparison.
     *
     * TXT: strip quotes and join 255-byte chunks. MX: drop trailing dots on the
     * exchange hostname (`10 mail.let.az.` → `10 mail.let.az`).
     */
    private function comparable(string $type, string $value): string
    {
        $value = trim($value);
        $type = strtoupper($type);
        if ($type === 'TXT') {
            // Handles `"aaa" "bbb"`, `"aaa"\n"bbb"`, and already-joined forms.
            $value = preg_replace('/"\s*"/', '', $value) ?? $value;
            $value = str_replace('"', '', $value);
        }
        if ($type === 'MX') {
            // "10 mail.example.com." — priority stays; host loses trailing dot.
            if (preg_match('/^(\d+)\s+(\S+)\.?$/', $value, $m) === 1) {
                $value = $m[1] . ' ' . rtrim($m[2], '.');
            }
        }
        $value = rtrim($value, '.');

        return strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
