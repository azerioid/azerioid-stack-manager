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

    // --- A83: one-time recovery codes -----------------------------------------

    private const RECOVERY_CODE_COUNT = 8;

    /**
     * Fresh set of high-entropy one-time recovery codes (plaintext, for display).
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars, ~40 bits
        }

        return $codes;
    }

    /**
     * Persist recovery codes, encrypted at rest like the TOTP secret. Pass the plain
     * codes; they are shown to the operator once and then only matched against.
     *
     * @param  list<string>  $codes
     */
    public function storeRecoveryCodes(User $user, array $codes): void
    {
        $user->forceFill([
            'two_factor_recovery_codes' => $codes === [] ? null : Crypt::encryptString(json_encode(array_values($codes))),
        ])->save();
    }

    /** @return list<string> the remaining recovery codes (plaintext), or [] */
    public function recoveryCodes(User $user): array
    {
        if (! filled($user->two_factor_recovery_codes)) {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Consume a recovery code: if $code matches a remaining code it is removed (one-time)
     * and true is returned. Comparison is normalized (case, dashes/spaces) and
     * constant-time per candidate.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $needle = self::normalizeRecoveryCode($code);
        if ($needle === '') {
            return false;
        }
        $codes = $this->recoveryCodes($user);
        $match = null;
        foreach ($codes as $i => $stored) {
            if (hash_equals(self::normalizeRecoveryCode($stored), $needle)) {
                $match = $i;
            }
        }
        if ($match === null) {
            return false;
        }
        unset($codes[$match]);
        $this->storeRecoveryCodes($user, array_values($codes));

        return true;
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $code));
    }
}
