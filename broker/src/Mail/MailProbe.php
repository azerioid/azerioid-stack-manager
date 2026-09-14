<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

/**
 * Outbound :25 reachability and open-relay self-test.
 *
 * A36 §7.4 makes this core UX, not a footnote: DigitalOcean and peers block
 * outbound SMTP by default on newer accounts, so most operators need a relay.
 * The status wording below is mandated copy shared by the UI health strip and
 * `azerioid mail status` — do not paraphrase it at a call site.
 *
 * "Open" is only ever reported after a real successful connect. A probe that
 * errors, times out, or is filtered reports blocked.
 */
final class MailProbe
{
    public const AVAILABLE = 'Direct mail delivery: available';
    public const BLOCKED = 'Direct mail delivery: blocked by your provider — configure a relay to send mail';

    public const PROBE_HOST = 'gmail-smtp-in.l.google.com';
    public const PROBE_PORT = 25;

    /** Relay self-test uses a domain we do not host; accepting it would prove an open relay. */
    private const RELAY_TEST_RECIPIENT = 'relay-test@example.com';

    /** @var (callable(string,int,int):array{connected:bool,banner:string,error:string})|null */
    private $connector;

    /** @var (callable(string,int,int,list<string>):list<string>)|null */
    private $dialogue;

    /**
     * @param (callable(string,int,int):array{connected:bool,banner:string,error:string})|null $connector
     * @param (callable(string,int,int,list<string>):list<string>)|null                        $dialogue
     */
    public function __construct(?callable $connector = null, ?callable $dialogue = null)
    {
        $this->connector = $connector;
        $this->dialogue = $dialogue;
    }

    /** The one place the direct-vs-blocked sentence is chosen. */
    public static function message(bool $open): string
    {
        return $open ? self::AVAILABLE : self::BLOCKED;
    }

    /**
     * @return array{
     *   open:bool,host:string,port:int,latency_ms:int,banner:string,
     *   error:string,message:string,delivery_mode_hint:string
     * }
     */
    public function outbound25(int $timeoutSeconds = 8): array
    {
        $started = microtime(true);
        $result = ($this->connector ?? self::defaultConnector(...))(self::PROBE_HOST, self::PROBE_PORT, $timeoutSeconds);
        $open = (bool) ($result['connected'] ?? false);

        return [
            'open' => $open,
            'host' => self::PROBE_HOST,
            'port' => self::PROBE_PORT,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'banner' => trim((string) ($result['banner'] ?? '')),
            'error' => $open ? '' : trim((string) ($result['error'] ?? 'Connection failed or timed out.')),
            'message' => self::message($open),
            'delivery_mode_hint' => $open ? 'direct' : 'smarthost',
        ];
    }

    /**
     * Unauthenticated relay to a foreign domain must be rejected. A 2xx on RCPT TO
     * means this host will relay for anyone — the failure mode that turns a VPS
     * into a spam cannon within minutes (A36 §5.2).
     *
     * Connect via a non-loopback address (typically the server's public IP). Testing
     * against 127.0.0.1 is meaningless: `mynetworks` always includes localhost, so
     * local submission is permitted by design and would false-positive as an open relay.
     *
     * @return array{passed:bool,checked:bool,detail:string,transcript:list<string>}
     */
    public function relaySelftest(string $helo, int $timeoutSeconds = 8, string $smtpHost = '127.0.0.1'): array
    {
        $commands = [
            'EHLO ' . $helo,
            'MAIL FROM:<relay-test@' . $helo . '>',
            'RCPT TO:<' . self::RELAY_TEST_RECIPIENT . '>',
            'QUIT',
        ];
        $transcript = ($this->dialogue ?? self::defaultDialogue(...))($smtpHost, 25, $timeoutSeconds, $commands);
        if ($transcript === []) {
            return [
                'passed' => false,
                'checked' => false,
                'detail' => 'Could not connect to the SMTP listener on port 25 at ' . $smtpHost . '.',
                'transcript' => [],
            ];
        }

        return self::classifyRelayTranscript($transcript);
    }

    /**
     * @param  list<string>  $transcript  responses aligned to EHLO, MAIL FROM, RCPT TO, QUIT
     * @return array{passed:bool,checked:bool,detail:string,transcript:list<string>}
     */
    public static function classifyRelayTranscript(array $transcript): array
    {
        $rcptReply = null;
        foreach ($transcript as $line) {
            if (!str_starts_with($line, 'RCPT TO')) {
                continue;
            }
            $separator = strpos($line, '=');
            $rcptReply = $separator === false ? '' : trim(substr($line, $separator + 1));
        }
        if ($rcptReply === null || $rcptReply === '') {
            return [
                'passed' => false,
                'checked' => false,
                'detail' => 'The server did not answer RCPT TO; relay behaviour is unverified.',
                'transcript' => $transcript,
            ];
        }
        $accepted = false;
        // Prefer the final SMTP reply code in the RCPT response (handles multiline joins).
        if (preg_match_all('/(?:^|\|\s)(\d{3})(?:\s|-)/', $rcptReply, $matches) && $matches[1] !== []) {
            $code = (string) end($matches[1]);
            $accepted = str_starts_with($code, '2');
        } elseif (preg_match('/^2\d\d/', $rcptReply) === 1) {
            $accepted = true;
        }

        return [
            'passed' => !$accepted,
            'checked' => true,
            'detail' => $accepted
                ? 'OPEN RELAY: this server accepted unauthenticated mail for a domain it does not host.'
                : 'Unauthenticated relay to a foreign domain was rejected, as required.',
            'transcript' => $transcript,
        ];
    }

    /** @return array{connected:bool,banner:string,error:string} */
    private static function defaultConnector(string $host, int $port, int $timeoutSeconds): array
    {
        $errno = 0;
        $errstr = '';
        $stream = @fsockopen($host, $port, $errno, $errstr, max(1, $timeoutSeconds));
        if ($stream === false) {
            return ['connected' => false, 'banner' => '', 'error' => $errstr !== '' ? $errstr : 'Connection refused or filtered.'];
        }
        stream_set_timeout($stream, max(1, $timeoutSeconds));
        $banner = (string) @fgets($stream, 512);
        @fclose($stream);

        return ['connected' => true, 'banner' => $banner, 'error' => ''];
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    private static function defaultDialogue(string $host, int $port, int $timeoutSeconds, array $commands): array
    {
        $errno = 0;
        $errstr = '';
        $stream = @fsockopen($host, $port, $errno, $errstr, max(1, $timeoutSeconds));
        if ($stream === false) {
            return [];
        }
        stream_set_timeout($stream, max(1, $timeoutSeconds));
        $transcript = ['BANNER=' . self::readSmtpReply($stream)];
        foreach ($commands as $command) {
            @fwrite($stream, $command . "\r\n");
            $transcript[] = $command . ' =' . self::readSmtpReply($stream);
        }
        @fclose($stream);

        return $transcript;
    }

    /** Drain a full SMTP reply (including multiline `250-…` / final `250 …`). */
    private static function readSmtpReply($stream): string
    {
        $lines = [];
        while (($line = @fgets($stream, 1024)) !== false) {
            $trim = rtrim($line, "\r\n");
            $lines[] = $trim;
            if (preg_match('/^\d{3} /', $trim) === 1) {
                break;
            }
        }

        return implode(' | ', $lines);
    }
}
