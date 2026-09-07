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
        $rt->script(['/usr/bin/dig', '+short', 'A', 'raww.az'], 0, '');
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'raww.az'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'raww.az', ['201.79.10.81']);
        $this->assertStringContainsString('does not resolve', $msg);
    }

    public function test_cloudflare_wrong_target(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', 'A', 'abc.az'], 0, "104.21.96.9\n172.67.150.31\n");
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'abc.az'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'abc.az', ['201.79.10.81']);
        $this->assertStringContainsString('Cloudflare', $msg);
    }

    public function test_correct_dns_still_pending(): void
    {
        $rt = new FakeRuntime();
        $rt->script(['/usr/bin/dig', '+short', 'A', 'let.az'], 0, "201.79.10.81\n");
        $rt->script(['/usr/bin/dig', '+short', 'AAAA', 'let.az'], 0, '');
        $msg = AcmeStatusHint::explainMissingCert($rt, 'let.az', ['201.79.10.81']);
        $this->assertStringStartsWith('Pending', $msg);
    }
}
