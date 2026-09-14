<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Mail\MailProbe;
use PHPUnit\Framework\TestCase;

final class MailProbeTest extends TestCase
{
    public function test_open_port_reports_the_exact_available_sentence(): void
    {
        $probe = new MailProbe(static fn (): array => [
            'connected' => true,
            'banner' => '220 mx.google.com ESMTP',
            'error' => '',
        ]);

        $result = $probe->outbound25(1);
        $this->assertTrue($result['open']);
        $this->assertSame('Direct mail delivery: available', $result['message']);
        $this->assertSame('direct', $result['delivery_mode_hint']);
        $this->assertSame('gmail-smtp-in.l.google.com', $result['host']);
        $this->assertSame(25, $result['port']);
    }

    public function test_blocked_port_reports_the_exact_relay_sentence(): void
    {
        $probe = new MailProbe(static fn (): array => [
            'connected' => false,
            'banner' => '',
            'error' => 'Connection timed out',
        ]);

        $result = $probe->outbound25(1);
        $this->assertFalse($result['open']);
        $this->assertSame(
            'Direct mail delivery: blocked by your provider — configure a relay to send mail',
            $result['message']
        );
        $this->assertSame('smarthost', $result['delivery_mode_hint']);
        $this->assertSame('Connection timed out', $result['error']);
    }

    public function test_message_helper_is_the_single_source_of_the_wording(): void
    {
        $this->assertSame(MailProbe::AVAILABLE, MailProbe::message(true));
        $this->assertSame(MailProbe::BLOCKED, MailProbe::message(false));
    }

    public function test_relay_selftest_fails_when_foreign_recipient_is_accepted(): void
    {
        $result = MailProbe::classifyRelayTranscript([
            'BANNER=220 mail.raww.az ESMTP',
            'EHLO mail.raww.az =250-mail.raww.az',
            'MAIL FROM:<relay-test@mail.raww.az> =250 2.1.0 Ok',
            'RCPT TO:<relay-test@example.com> =250 2.1.5 Ok',
            'QUIT =221 Bye',
        ]);

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('OPEN RELAY', $result['detail']);
    }

    public function test_relay_selftest_passes_when_foreign_recipient_is_rejected(): void
    {
        $result = MailProbe::classifyRelayTranscript([
            'BANNER=220 mail.raww.az ESMTP',
            'EHLO mail.raww.az =250-mail.raww.az',
            'MAIL FROM:<relay-test@mail.raww.az> =250 2.1.0 Ok',
            'RCPT TO:<relay-test@example.com> =554 5.7.1 Relay access denied',
            'QUIT =221 Bye',
        ]);

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['passed']);
    }

    public function test_unanswered_rcpt_is_unverified_rather_than_a_pass(): void
    {
        $result = MailProbe::classifyRelayTranscript(['BANNER=220 mail.raww.az ESMTP']);

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['passed']);
    }

    public function test_unreachable_local_listener_never_claims_a_pass(): void
    {
        $probe = new MailProbe(null, static fn (): array => []);
        $result = $probe->relaySelftest('mail.raww.az', 1);

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Could not connect', $result['detail']);
    }

    public function test_relay_selftest_uses_injected_smtp_host_not_loopback_by_default_in_dialogue(): void
    {
        $seenHost = null;
        $probe = new MailProbe(null, static function (string $host, int $port, int $timeout, array $commands) use (&$seenHost): array {
            $seenHost = $host;

            return [
                'BANNER=220 mail.raww.az ESMTP',
                'EHLO mail.raww.az =250 mail.raww.az',
                'MAIL FROM:<relay-test@mail.raww.az> =250 2.1.0 Ok',
                'RCPT TO:<relay-test@example.com> =554 5.7.1 Relay access denied',
                'QUIT =221 Bye',
            ];
        });

        $result = $probe->relaySelftest('mail.raww.az', 1, '203.0.113.10');
        $this->assertSame('203.0.113.10', $seenHost);
        $this->assertTrue($result['passed']);
    }
}
