<?php

declare(strict_types=1);

use App\Domain\Files\UploadRules;
use Carbon\CarbonImmutable;

// Fixture files are generated per test in the system temp dir and removed afterwards.

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sth-upload-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

function uploadFixture(string $dir, string $name, string $kind): string
{
    $path = $dir.'/'.$name;

    if (in_array($kind, ['png', 'jpeg', 'webp'], true)) {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 49, 72, 214));
        match ($kind) {
            'png' => imagepng($image, $path),
            'jpeg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
        };

        return $path;
    }

    file_put_contents($path, match ($kind) {
        'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
        'pdf-late-magic' => "garbage\n%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
        'php' => "<?php echo 'hi';\n",
        'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><script>alert(1)</script></svg>',
        'html' => "<!DOCTYPE html><html><body>hi</body></html>\n",
        'empty' => '',
    });

    return $path;
}

it('exposes the allowlist and 10 MB limit', function () {
    $rules = new UploadRules;

    expect($rules->extensions())->toBe(['pdf', 'jpg', 'jpeg', 'png', 'webp'])
        ->and($rules->mimeTypes())->toBe(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
        ->and($rules->maxBytes())->toBe(10 * 1024 * 1024)
        ->and($rules->maxKilobytes())->toBe(10240)
        ->and($rules->isAllowedExtension('.JPG'))->toBeTrue()
        ->and($rules->isAllowedExtension('svg'))->toBeFalse()
        ->and($rules->isAllowedMime('image/svg+xml'))->toBeFalse()
        ->and($rules->extensionForMime('image/jpeg'))->toBe('jpg');
});

it('accepts real pdf and image files', function (string $name, string $kind, string $mime) {
    $rules = new UploadRules;
    $path = uploadFixture($this->dir, $name, $kind);

    expect($rules->sniffMime($path))->toBe($mime)
        ->and($rules->validate($path, $name))->toBe([]);
})->with([
    ['sertifikat.pdf', 'pdf', 'application/pdf'],
    ['foto.png', 'png', 'image/png'],
    ['foto.JPEG', 'jpeg', 'image/jpeg'],
    ['foto.jpg', 'jpeg', 'image/jpeg'],
    ['poster.webp', 'webp', 'image/webp'],
]);

it('rejects disguised or disallowed content', function (string $name, string $kind, array $errors) {
    expect((new UploadRules)->validate(uploadFixture($this->dir, $name, $kind), $name))->toBe($errors);
})->with([
    'php renamed to jpg' => ['foto.jpg', 'php', [UploadRules::MSG_CONTENT]],
    'svg renamed to png' => ['logo.png', 'svg', [UploadRules::MSG_CONTENT]],
    'html renamed to pdf' => ['juknis.pdf', 'html', [UploadRules::MSG_CONTENT]],
    'svg with its own extension' => ['logo.svg', 'svg', ['Jenis file tidak diizinkan. Pakai PDF, JPG, PNG, atau WEBP.', UploadRules::MSG_CONTENT]],
    'real png with php extension' => ['shell.php', 'png', ['Jenis file tidak diizinkan. Pakai PDF, JPG, PNG, atau WEBP.']],
    'empty file' => ['kosong.pdf', 'empty', [UploadRules::MSG_EMPTY]],
]);

it('requires the PDF magic at the very start', function () {
    $rules = new UploadRules;
    $path = uploadFixture($this->dir, 'aneh.pdf', 'pdf-late-magic');

    expect($rules->hasPdfMagic($path))->toBeFalse()
        ->and($rules->validate($path, 'aneh.pdf'))->toBeIn([[UploadRules::MSG_BAD_PDF], [UploadRules::MSG_CONTENT]]);
});

it('enforces the size limit', function () {
    $rules = new UploadRules(maxBytes: 512 * 1024);
    $path = uploadFixture($this->dir, 'besar.pdf', 'pdf');
    file_put_contents($path, str_repeat('0', 600 * 1024), FILE_APPEND);

    expect($rules->validate($path, 'besar.pdf'))->toBe(['Ukuran file maksimal 0,5 MB.'])
        ->and((new UploadRules)->validate($path, 'besar.pdf'))->toBe([]);
});

it('reports unreadable paths', function () {
    $rules = new UploadRules;

    expect($rules->validate($this->dir.'/missing.pdf', 'missing.pdf'))->toBe([UploadRules::MSG_UNREADABLE])
        ->and($rules->sniffMime($this->dir.'/missing.pdf'))->toBeNull();
});

it('can be narrowed to images only', function () {
    $rules = new UploadRules(extensions: ['jpg', 'jpeg', 'png', 'webp']);
    $pdf = uploadFixture($this->dir, 'sertifikat.pdf', 'pdf');

    expect($rules->validate($pdf, 'sertifikat.pdf'))->toBe([
        'Jenis file tidak diizinkan. Pakai JPG, PNG, atau WEBP.',
        UploadRules::MSG_CONTENT,
    ]);
});

it('rejects extensions outside the global allowlist', function () {
    new UploadRules(extensions: ['pdf', 'svg']);
})->throws(InvalidArgumentException::class);

it('generates random private storage paths from the sniffed MIME', function () {
    $rules = new UploadRules;
    $now = CarbonImmutable::parse('2026-10-01');

    $first = $rules->storagePath('image/jpeg', $now);
    $second = $rules->storagePath('image/jpeg', $now);

    expect($first)->toMatch('~^lampiran/2026/10/[0-9a-hjkmnp-tv-z]{26}\.jpg$~')
        ->and($second)->not->toBe($first)
        ->and($rules->storagePath('application/pdf', $now, 'laporan/riset'))->toStartWith('laporan/riset/2026/10/')
        ->and($rules->storagePath('application/pdf', $now))->toEndWith('.pdf');
});

it('refuses unsafe storage inputs', function (string $mime, string $directory) {
    (new UploadRules)->storagePath($mime, null, $directory);
})->with([
    'svg mime' => ['image/svg+xml', 'lampiran'],
    'traversal' => ['image/png', '../public'],
    'absolute' => ['image/png', '/etc'],
    'uppercase' => ['image/png', 'Lampiran'],
])->throws(InvalidArgumentException::class);

it('sanitizes original names for display', function (string $input, string $expected) {
    expect((new UploadRules)->sanitizeOriginalName($input))->toBe($expected);
})->with([
    ['sertifikat OSN.pdf', 'sertifikat OSN.pdf'],
    ['C:\\fakepath\\foto.jpg', 'foto.jpg'],
    ['../../etc/passwd', 'passwd'],
    ["nama\x00jahat\n.pdf", 'namajahat.pdf'],
    ['..', 'file'],
    [str_repeat('a', 200).'.pdf', str_repeat('a', 150)],
]);
