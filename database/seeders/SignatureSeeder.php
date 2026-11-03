<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FilePurpose;
use App\Enums\SettingKey;
use App\Models\File;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dummy signature + stamp image for local/testing letters. It is obviously fake ("CONTOH TTD + STEMPEL —
 * hanya untuk uji") and is NEVER seeded in production: there the Admin uploads the real image in Pengaturan.
 *
 * - database/seeders/assets/ttd-stempel-contoh.png is generated with GD when missing (commit-able asset)
 * - copied to storage/app/private/ttd/ttd-stempel-contoh.png, registered in `files`, and
 *   settings.signature_file_id points at it (only if no other signature is configured).
 */
class SignatureSeeder extends Seeder
{
    public const ASSET = 'seeders/assets/ttd-stempel-contoh.png';

    public const STORAGE_PATH = 'ttd/ttd-stempel-contoh.png';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('SignatureSeeder dilewati di production: Admin meng-upload tanda tangan asli.');

            return;
        }

        $asset = database_path(self::ASSET);

        if (! is_file($asset)) {
            if (! is_dir(dirname($asset))) {
                mkdir(dirname($asset), 0755, true);
            }

            file_put_contents($asset, self::generatePng());
        }

        $bytes = (string) file_get_contents($asset);
        Storage::disk('local')->put(self::STORAGE_PATH, $bytes);

        $file = File::query()->where('path', self::STORAGE_PATH)->first() ?? new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => self::STORAGE_PATH,
            'original_name' => 'ttd-stempel-contoh.png',
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'purpose' => FilePurpose::Signature,
            'owner_user_id' => null,
        ])->save();

        $current = Setting::get(SettingKey::SignatureFileId);

        if ($current === null || $current === $file->getKey() || File::query()->whereKey($current)->doesntExist()) {
            Setting::put(SettingKey::SignatureFileId, $file->getKey());
        }
    }

    /** Transparent 640×320 PNG: a squiggle "signature", a double-ring stamp and the CONTOH label. */
    public static function generatePng(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Ekstensi GD dibutuhkan untuk membuat gambar tanda tangan contoh.');
        }

        $width = 640;
        $height = 320;
        $image = imagecreatetruecolor($width, $height);

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        $ink = (int) imagecolorallocatealpha($image, 24, 42, 120, 0);
        $stamp = (int) imagecolorallocatealpha($image, 49, 72, 214, 35);

        // Stamp: two rings (GD ignores thickness for ellipses, so draw concentric ones).
        foreach ([0, 1, 2, 3] as $offset) {
            imageellipse($image, 450, 145, 230 - $offset * 2, 230 - $offset * 2, $stamp);
            imageellipse($image, 450, 145, 176 - $offset * 2, 176 - $offset * 2, $stamp);
        }

        // Signature: a damped wave.
        imagesetthickness($image, 3);
        $previous = null;

        for ($x = 50; $x <= 380; $x += 2) {
            $progress = ($x - 50) / 330;
            $y = 165 + (int) round(38 * sin($x / 17) * (1 - 0.6 * $progress) + 9 * sin($x / 4.5));

            if ($previous !== null) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }

            $previous = [$x, $y];
        }

        imagesetthickness($image, 1);

        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');

        if (function_exists('imagettftext') && is_file($font)) {
            imagettftext($image, 17, 0, 22, 298, $ink, $font, 'CONTOH TTD + STEMPEL — hanya untuk uji');
            imagettftext($image, 18, 0, 400, 153, $stamp, $font, 'CONTOH');
        } else {
            imagestring($image, 5, 22, 285, 'CONTOH TTD + STEMPEL - hanya untuk uji', $ink);
            imagestring($image, 5, 420, 138, 'CONTOH', $stamp);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
