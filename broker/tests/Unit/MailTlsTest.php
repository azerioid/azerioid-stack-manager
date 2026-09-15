<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Mail\MailTls;
use PHPUnit\Framework\TestCase;

final class MailTlsTest extends TestCase
{
    public function test_caddy_candidates_cover_acme_and_local_stores(): void
    {
        $candidates = MailTls::caddyCandidates('mail.example.com');
        $this->assertSame(
            '/var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/mail.example.com/mail.example.com.crt',
            $candidates[0][0]
        );
        $this->assertSame(
            '/var/lib/caddy/.local/share/caddy/certificates/local/mail.example.com/mail.example.com.key',
            $candidates[1][1]
        );
    }
}
