<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Tls\AcmeStatusHint;
use PHPUnit\Framework\TestCase;

final class AcmeStatusHintTest extends TestCase
{
    public function test_nxdomain_message(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', 'A', 'site2.example.com'], 0, '');
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'site2.example.com'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'site2.example.com', ['203.0.113.30']);
        $this->assertStringContainsString('does not resolve', $msg);
    }

    public function test_cloudflare_wrong_target(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', 'A', 'site4.example.com'], 0, "104.21.96.9\n172.67.150.31\n");
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'site4.example.com'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'site4.example.com', ['203.0.113.30']);
        $this->assertStringContainsString('Cloudflare', $msg);
    }

    public function test_correct_dns_still_pending(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', 'A', 'site3.example.com'], 0, "203.0.113.30\n");
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'site3.example.com'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'site3.example.com', ['203.0.113.30']);
        $this->assertStringStartsWith('Pending', $msg);
    }
}
