<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\FilePurpose;
use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tiny placeholder files for local demo data: a one-page PDF that says "Berkas contoh - data fiktif",
 * written to the private `local` disk under demo/ with a random name, plus its `files` row.
 * Never used outside local seeding.
 */
final class DemoFiles
{
    public const DIRECTORY = 'demo';

    public static function pdf(string $originalName, FilePurpose $purpose, ?int $ownerUserId = null, ?string $title = null): File
    {
        $bytes = self::pdfBytes([
            $title ?? pathinfo($originalName, PATHINFO_FILENAME),
            'Berkas contoh - data fiktif SMAGA Talent Hub.',
            'Bukan dokumen resmi.',
            'Nama berkas: '.$originalName,
        ]);

        $path = self::DIRECTORY.'/'.Str::lower((string) Str::ulid()).'.pdf';
        Storage::disk('local')->put($path, $bytes);

        $file = new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $originalName,
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'purpose' => $purpose,
            'owner_user_id' => $ownerUserId,
        ])->save();

        return $file;
    }

    /**
     * Minimal valid PDF 1.4 (Helvetica, ASCII text only).
     *
     * @param  list<string>  $lines
     */
    public static function pdfBytes(array $lines): string
    {
        $content = "BT\n/F1 14 Tf\n72 770 Td\n20 TL\n";

        foreach ($lines as $i => $line) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '-', $line) ?? '';
            $escaped = strtr($ascii, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $content .= ($i === 0 ? '' : "T*\n")."({$escaped}) Tj\n";
        }

        $content .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n"."0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
