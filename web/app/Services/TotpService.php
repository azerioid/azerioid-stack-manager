<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

final class TotpService
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA())
    {
        $this->google2fa->setWindow(1);
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        return (bool) $this->google2fa->verifyKey($secret, $code);
    }

    public function qrSvg(string $email, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(config('app.name', 'AZERIOID Stack Manager'), $email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd()));
        return $writer->writeString($url);
    }

    public function storeUnconfirmed(User $user, string $secret): void
    {
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function confirm(User $user): void
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    /** Begin re-enrollment: new secret, unconfirmed until a valid code is entered. */
    public function beginReset(User $user): string
    {
        $secret = $this->generateSecret();
        $this->storeUnconfirmed($user, $secret);

        return $secret;
    }
}
