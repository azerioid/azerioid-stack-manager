<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Livewire 4 uses /livewire-{hash}/… — enrollment must still be allowed through 2FA middleware. */
final class RequireTwoFactorLivewirePathTest extends TestCase
{
    public function test_recognizes_hashed_livewire_update_paths(): void
    {
        $mw = new RequireTwoFactor();
        $ref = new \ReflectionClass($mw);
        $method = $ref->getMethod('isLivewireUpdateRequest');
        $method->setAccessible(true);

        $legacy = Request::create('/livewire/update', 'POST');
        $hashed = Request::create('/livewire-abc123def/update', 'POST');
        $other = Request::create('/vhosts', 'GET');

        $this->assertTrue($method->invoke($mw, $legacy));
        $this->assertTrue($method->invoke($mw, $hashed));
        $this->assertFalse($method->invoke($mw, $other));
    }
}
