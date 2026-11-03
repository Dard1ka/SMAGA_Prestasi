<?php

declare(strict_types=1);

/*
| <x-sth.file-drop> (r3 review, 2026-11-01): a screen that prints the messages of a picker elsewhere (Laporan hasil:
| the live region under the photo list) ties them to the input with a forwarded aria-describedby, merged after the
| component's own hint and error ids, and marks the zone and the input with `invalid` (or a forwarded aria-invalid).
| Without those attributes the component renders as before.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

/** The opening tag of the drop zone's <input> with that id. */
function fileDropInput(string $html, string $id): string
{
    expect(preg_match('~<input\b[^>]*\bid="'.$id.'"[^>]*>~', $html, $match))->toBe(1);

    return $match[0];
}

/** The opening tag of the visible drop zone (<label class="drop">) of that input. */
function fileDropZone(string $html, string $id): string
{
    expect(preg_match('~<label for="'.$id.'" class="drop"[^>]*>~', $html, $match))->toBe(1);

    return $match[0];
}

it('renders as before without the new attributes: one aria-describedby for the hint, no aria-invalid', function (): void {
    $html = (string) $this->blade('<x-sth.file-drop name="sertifikat" label="Sertifikat" accept=".pdf,.jpg,.jpeg,.png" />');
    $input = fileDropInput($html, 'f-sertifikat');

    // The same attributes in the same order as before the r3 review (white space aside).
    expect(trim((string) preg_replace('/\s+/', ' ', $input)))
        ->toBe('<input type="file" id="f-sertifikat" name="sertifikat" accept=".pdf,.jpg,.jpeg,.png" class="sr-only" aria-describedby="f-sertifikat-hint" >')
        ->and(fileDropZone($html, 'f-sertifikat'))->toBe('<label for="f-sertifikat" class="drop" data-sth-drop >')
        ->and($html)->not->toContain('aria-invalid');

    // Without a hint there is nothing to describe the input with.
    $bare = (string) $this->blade('<x-sth.file-drop name="poster" hint="" />');
    expect(fileDropInput($bare, 'f-poster'))->not->toContain('aria-describedby')
        ->and($bare)->not->toContain('aria-invalid');
});

it('marks the zone and the input for its own error and describes the input with hint and error', function (): void {
    $html = (string) $this->blade('<x-sth.file-drop name="sertifikat" error="Ukuran file maksimal 5 MB." />');
    $input = fileDropInput($html, 'f-sertifikat');

    expect($input)->toContain('aria-describedby="f-sertifikat-hint f-sertifikat-error"')
        ->and(substr_count($input, 'aria-invalid="true"'))->toBe(1)
        ->and(fileDropZone($html, 'f-sertifikat'))->toContain('aria-invalid="true"')
        ->and($html)->toContain('<p id="f-sertifikat-error" class="field-error">');
});

it('merges a forwarded aria-describedby after its own ids and marks both for invalid', function (): void {
    $html = (string) $this->blade('<x-sth.file-drop name="foto[]" id="lapor-foto" multiple invalid aria-describedby="lapor-foto-pesan" />');
    $input = fileDropInput($html, 'lapor-foto');

    expect($input)->toContain('aria-describedby="lapor-foto-hint lapor-foto-pesan"')
        ->and(substr_count($input, 'aria-describedby='))->toBe(1)
        ->and(substr_count($input, 'aria-invalid="true"'))->toBe(1)
        ->and(fileDropZone($html, 'lapor-foto'))->toContain('aria-invalid="true"');

    // With its own error too: hint, error, then the forwarded id; still one mark each.
    $both = (string) $this->blade('<x-sth.file-drop name="foto" id="lapor-foto" :invalid="true" error="Foto harus JPG." aria-describedby="lapor-foto-pesan" />');
    $input = fileDropInput($both, 'lapor-foto');
    expect($input)->toContain('aria-describedby="lapor-foto-hint lapor-foto-error lapor-foto-pesan"')
        ->and(substr_count($input, 'aria-invalid='))->toBe(1)
        ->and(substr_count(fileDropZone($both, 'lapor-foto'), 'aria-invalid='))->toBe(1);
});

it('treats a forwarded aria-invalid="true" as invalid and never prints a forwarded id twice', function (): void {
    $html = (string) $this->blade('<x-sth.file-drop name="foto" aria-invalid="true" aria-describedby="f-foto-hint  pesan-foto" />');
    $input = fileDropInput($html, 'f-foto');

    expect($input)->toContain('aria-describedby="f-foto-hint pesan-foto"')
        ->and(substr_count($input, 'aria-describedby='))->toBe(1)
        ->and(substr_count($input, 'aria-invalid='))->toBe(1)
        ->and(fileDropZone($html, 'f-foto'))->toContain('aria-invalid="true"');

    // Empty or null forwarded values change nothing.
    $quiet = (string) $this->blade('<x-sth.file-drop name="foto" :invalid="false" :aria-invalid="null" :aria-describedby="null" />');
    expect(fileDropInput($quiet, 'f-foto'))->toContain('aria-describedby="f-foto-hint"')
        ->and($quiet)->not->toContain('aria-invalid');
});
