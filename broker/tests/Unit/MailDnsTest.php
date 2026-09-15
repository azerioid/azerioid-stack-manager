<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Mail\MailDns;
use PHPUnit\Framework\TestCase;

final class MailDnsTest extends TestCase
{
    public function test_direct_mode_spf_authorizes_this_host(): void
    {
        $spf = MailDns::spf('mail.raww.az', '64.226.78.176', null);

        $this->assertSame('v=spf1 a:mail.raww.az ip4:64.226.78.176 ~all', $spf);
    }

    public function test_smarthost_mode_spf_authorizes_the_relay_not_this_host(): void
    {
        $spf = MailDns::spf('mail.raww.az', '64.226.78.176', ['host' => 'smtp.sendgrid.net', 'port' => 587]);

        $this->assertSame('v=spf1 include:sendgrid.net ~all', $spf);
        $this->assertStringNotContainsString('64.226.78.176', $spf, 'Relayed mail leaves from the provider, not this IP.');
        $this->assertStringNotContainsString('a:mail.raww.az', $spf);
    }

    public function test_unknown_relay_falls_back_to_including_the_relay_host(): void
    {
        $this->assertSame(
            'v=spf1 include:smtp.corp.example.net ~all',
            MailDns::spf('mail.raww.az', '64.226.78.176', ['host' => 'smtp.corp.example.net'])
        );
    }

    public function test_known_providers_map_to_their_published_include(): void
    {
        $this->assertSame('amazonses.com', MailDns::providerInclude('email-smtp.eu-central-1.amazonaws.com'));
        $this->assertSame('spf.mtasv.net', MailDns::providerInclude('smtp.postmarkapp.com'));
        $this->assertSame('mailgun.org', MailDns::providerInclude('SMTP.Mailgun.ORG'));
    }

    public function test_empty_smarthost_host_is_treated_as_direct(): void
    {
        $this->assertSame(
            'v=spf1 a:mail.raww.az ip4:64.226.78.176 ~all',
            MailDns::spf('mail.raww.az', '64.226.78.176', ['host' => '  '])
        );
    }

    public function test_record_set_covers_mx_a_spf_dmarc_dkim_and_ptr(): void
    {
        $records = MailDns::records(
            'raww.az',
            'mail.raww.az',
            '64.226.78.176',
            null,
            'azerioid',
            'v=DKIM1; k=rsa; p=MIIB'
        );
        $index = [];
        foreach ($records as $record) {
            $index[$record['type'] . ' ' . $record['name']] = $record['value'];
        }

        $this->assertSame('10 mail.raww.az', $index['MX raww.az']);
        $this->assertSame('64.226.78.176', $index['A mail.raww.az']);
        $this->assertSame('v=spf1 a:mail.raww.az ip4:64.226.78.176 ~all', $index['TXT raww.az']);
        $this->assertStringStartsWith('v=DMARC1; p=none;', $index['TXT _dmarc.raww.az']);
        $this->assertSame('v=DKIM1; k=rsa; p=MIIB', $index['TXT azerioid._domainkey.raww.az']);
        $this->assertSame('mail.raww.az', $index['PTR 64.226.78.176']);
    }

    public function test_dkim_record_is_omitted_until_a_key_exists(): void
    {
        $records = MailDns::records('raww.az', 'mail.raww.az', '64.226.78.176', null, 'azerioid', '');
        foreach ($records as $record) {
            $this->assertStringNotContainsString('_domainkey', $record['name']);
        }
    }

    public function test_verify_flags_an_spf_record_left_over_from_the_other_mode(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'raww.az'], 0, "\"v=spf1 a:mail.raww.az ip4:64.226.78.176 ~all\"\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'raww.az',
            'value' => 'v=spf1 include:sendgrid.net ~all',
            'purpose' => 'SPF',
            'required' => true,
        ]]);

        $this->assertSame('mismatch', $verified[0]['state']);
        $this->assertStringContainsString('current outbound mode', $verified[0]['note']);
    }

    public function test_verify_matches_quoted_and_chunked_txt_answers(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', '_dmarc.raww.az'], 0, '"v=DMARC1; p=none; rua=mailto:postmaster@raww.az; fo=1"');

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => '_dmarc.raww.az',
            'value' => MailDns::dmarc('raww.az'),
            'purpose' => 'DMARC',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state']);
    }

    public function test_verify_matches_apex_mx_with_trailing_dot(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'MX', 'let.az'], 0, "10 mail.let.az.\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'MX',
            'name' => 'let.az',
            'value' => '10 mail.let.az',
            'purpose' => 'MX',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state'], 'Trailing-dot MX exchanges must compare equal to the suggestion.');
    }

    public function test_verify_matches_apex_spf_via_public_resolver(): void
    {
        $rt = new FakeRuntime();
        // First resolver empty (simulates flaky 127.0.0.53), second succeeds.
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'let.az'], 0, '');
        $rt->script(['/usr/bin/dig', '+short', '@8.8.8.8', 'TXT', 'let.az'], 0, "\"v=spf1 a:mail.let.az ip4:64.226.78.176 ~all\"\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'let.az',
            'value' => 'v=spf1 a:mail.let.az ip4:64.226.78.176 ~all',
            'purpose' => 'SPF',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state']);
    }

    public function test_verify_concatenates_multi_line_dkim_txt_chunks(): void
    {
        $rt = new FakeRuntime();
        // dig sometimes emits each 255-byte TXT string on its own line.
        $rt->script(
            ['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'az._domainkey.let.az'],
            0,
            "\"v=DKIM1; h=sha256; k=rsa; p=AAA\"\n\"BBB\"\n"
        );

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'az._domainkey.let.az',
            'value' => 'v=DKIM1; h=sha256; k=rsa; p=AAABBB',
            'purpose' => 'DKIM',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state'], 'Multi-segment DKIM TXT must be joined before compare.');
    }

    public function test_verify_concatenates_same_line_quoted_dkim_chunks(): void
    {
        $rt = new FakeRuntime();
        $rt->script(
            ['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'az._domainkey.let.az'],
            0,
            "\"v=DKIM1; h=sha256; k=rsa; p=AAA\" \"BBB\"\n"
        );

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'az._domainkey.let.az',
            'value' => 'v=DKIM1; h=sha256; k=rsa; p=AAABBB',
            'purpose' => 'DKIM',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state']);
    }

    public function test_ptr_is_reported_as_provider_side_rather_than_missing(): void
    {
        $verified = (new MailDns(new FakeRuntime()))->verify([[
            'type' => 'PTR',
            'name' => '64.226.78.176',
            'value' => 'mail.raww.az',
            'purpose' => 'Reverse DNS',
            'required' => true,
        ]]);

        $this->assertSame('unknown', $verified[0]['state']);
        $this->assertStringContainsString('VPS provider', $verified[0]['note']);
    }
}
