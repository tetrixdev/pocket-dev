<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

class TotpEnrollmentService
{
    public function __construct(
        protected Google2FA $google2fa,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    public function qrCodeSvg(string $email, string $secret, int $size = 200): string
    {
        $url = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $email,
            $secret,
        );

        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        ));

        return $writer->writeString($url);
    }

    /**
     * @return array<string>
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = RecoveryCode::generate();
        }
        return $codes;
    }

    public function encryptSecret(string $secret): string
    {
        return Fortify::currentEncrypter()->encrypt($secret);
    }

    public function decryptSecret(string $encryptedSecret): string
    {
        return Fortify::currentEncrypter()->decrypt($encryptedSecret);
    }

    /**
     * @param array<string> $codes
     */
    public function encryptRecoveryCodes(array $codes): string
    {
        return Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR));
    }
}
