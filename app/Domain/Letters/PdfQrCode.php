<?php

declare(strict_types=1);

namespace App\Domain\Letters;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;
use RuntimeException;

/**
 * QR code as PNG bytes for the letter verification link (SECURITY §3g, U-05). Encoded with
 * bacon/bacon-qr-code (already installed through Fortify) and drawn with GD, so no SVG and no remote
 * or data: URIs are needed inside dompdf: the PNG is written into the render sandbox (PdfRenderer).
 */
final class PdfQrCode
{
    public const QUIET_ZONE = 4;

    /** Black-on-white PNG, `$scale` pixels per module, with the standard 4-module quiet zone. */
    public function png(string $text, int $scale = 8): string
    {
        if ($text === '' || strlen($text) > 512) {
            throw new InvalidArgumentException('Isi kode QR harus 1–512 karakter.');
        }

        if (! function_exists('imagecreate')) {
            throw new RuntimeException('Ekstensi GD dibutuhkan untuk membuat kode QR.');
        }

        $scale = max(1, min($scale, 20));
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth();
        $size = ($modules + 2 * self::QUIET_ZONE) * $scale;

        $image = imagecreate($size, $size);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + self::QUIET_ZONE) * $scale;
                    $top = ($y + self::QUIET_ZONE) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
