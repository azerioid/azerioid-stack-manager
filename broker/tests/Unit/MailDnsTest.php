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
        $spf = MailDns::spf('mail.site2.example.com', '203.0.113.10', null);

        $this->assertSame('v=spf1 a:mail.site2.example.com ip4:203.0.113.10 ~all', $spf);
    }

    public function test_smarthost_mode_spf_authorizes_the_relay_not_this_host(): void
    {
        $spf = MailDns::spf('mail.site2.example.com', '203.0.113.10', ['host' => 'smtp.sendgrid.net', 'port' => 587]);

        $this->assertSame('v=spf1 include:sendgrid.net ~all', $spf);
        $this->assertStringNotContainsString('203.0.113.10', $spf, 'Relayed mail leaves from the provider, not this IP.');
        $this->assertStringNotContainsString('a:mail.site2.example.com', $spf);
    }

    public function test_unknown_relay_falls_back_to_including_the_relay_host(): void
    {
        $this->assertSame(
            'v=spf1 include:smtp.corp.example.net ~all',
            MailDns::spf('mail.site2.example.com', '203.0.113.10', ['host' => 'smtp.corp.example.net'])
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
            'v=spf1 a:mail.site2.example.com ip4:203.0.113.10 ~all',
            MailDns::spf('mail.site2.example.com', '203.0.113.10', ['host' => '  '])
        );
    }

    public function test_record_set_covers_mx_a_spf_dmarc_dkim_and_ptr(): void
    {
        $records = MailDns::records(
            'site2.example.com',
            'mail.site2.example.com',
            '203.0.113.10',
            null,
            'azerioid',
            'v=DKIM1; k=rsa; p=MIIB'
        );
        $index = [];
        foreach ($records as $record) {
            $index[$record['type'] . ' ' . $record['name']] = $record['value'];
        }

        $this->assertSame('10 mail.site2.example.com', $index['MX site2.example.com']);
        $this->assertSame('203.0.113.10', $index['A mail.site2.example.com']);
        $this->assertSame('v=spf1 a:mail.site2.example.com ip4:203.0.113.10 ~all', $index['TXT site2.example.com']);
        $this->assertStringStartsWith('v=DMARC1; p=none;', $index['TXT _dmarc.site2.example.com']);
        $this->assertSame('v=DKIM1; k=rsa; p=MIIB', $index['TXT azerioid._domainkey.site2.example.com']);
        $this->assertSame('mail.site2.example.com', $index['PTR 203.0.113.10']);
    }

    public function test_dkim_record_is_omitted_until_a_key_exists(): void
    {
        $records = MailDns::records('site2.example.com', 'mail.site2.example.com', '203.0.113.10', null, 'azerioid', '');
        foreach ($records as $record) {
            $this->assertStringNotContainsString('_domainkey', $record['name']);
        }
    }

    public function test_verify_flags_an_spf_record_left_over_from_the_other_mode(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'site2.example.com'], 0, "\"v=spf1 a:mail.site2.example.com ip4:203.0.113.10 ~all\"\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'site2.example.com',
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
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', '_dmarc.site2.example.com'], 0, '"v=DMARC1; p=none; rua=mailto:postmaster@site2.example.com; fo=1"');

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => '_dmarc.site2.example.com',
            'value' => MailDns::dmarc('site2.example.com'),
            'purpose' => 'DMARC',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state']);
    }

    public function test_verify_matches_apex_mx_with_trailing_dot(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'MX', 'site3.example.com'], 0, "10 mail.site3.example.com.\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'MX',
            'name' => 'site3.example.com',
            'value' => '10 mail.site3.example.com',
            'purpose' => 'MX',
            'required' => true,
        ]]);

        $this->assertSame('ok', $verified[0]['state'], 'Trailing-dot MX exchanges must compare equal to the suggestion.');
    }

    public function test_verify_matches_apex_spf_via_public_resolver(): void
    {
        $rt = new FakeRuntime();
        // First resolver empty (simulates flaky 127.0.0.53), second succeeds.
        $rt->script(['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'site3.example.com'], 0, '');
        $rt->script(['/usr/bin/dig', '+short', '@8.8.8.8', 'TXT', 'site3.example.com'], 0, "\"v=spf1 a:mail.site3.example.com ip4:203.0.113.10 ~all\"\n");

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'site3.example.com',
            'value' => 'v=spf1 a:mail.site3.example.com ip4:203.0.113.10 ~all',
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
            ['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'az._domainkey.site3.example.com'],
            0,
            "\"v=DKIM1; h=sha256; k=rsa; p=AAA\"\n\"BBB\"\n"
        );

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'az._domainkey.site3.example.com',
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
            ['/usr/bin/dig', '+short', '@1.1.1.1', 'TXT', 'az._domainkey.site3.example.com'],
            0,
            "\"v=DKIM1; h=sha256; k=rsa; p=AAA\" \"BBB\"\n"
        );

        $verified = (new MailDns($rt))->verify([[
            'type' => 'TXT',
            'name' => 'az._domainkey.site3.example.com',
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
            'name' => '203.0.113.10',
            'value' => 'mail.site2.example.com',
            'purpose' => 'Reverse DNS',
            'required' => true,
        ]]);

        $this->assertSame('unknown', $verified[0]['state']);
        $this->assertStringContainsString('VPS provider', $verified[0]['note']);
    }
}
