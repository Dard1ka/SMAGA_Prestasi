<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\MemberRole;
use App\Enums\ReportFileKind;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\Registration;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M3-C · file preview popup, the serving side (FileController, SecurityHeaders; SECURITY §3d, §3f).
| `?tampil=1` on `files.show` sends a PDF inline so the popup can show it in a frame of the same origin. That one
| answer may be framed by this origin and by nothing else; every other answer keeps `X-Frame-Options: DENY`,
| `frame-ancestors 'none'` and, for files, `sandbox`. Only PDFs and re-encoded images are ever inline. Policy,
| 404-for-not-yours and the throttle are the same as for a download.
*/

uses(RefreshDatabase::class);

const M3C_PDF_BYTES = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";

beforeEach(function (): void {
    $this->withoutVite();
    F::boot();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->report = F::pendingReport($this->nadia);
});

/** One evidence file of Nadia's report, with bytes on the test's own private disk. */
function m3cEvidence(Report $report, string $mime = 'application/pdf', string $name = 'sertifikat-okn-undip-2026.pdf', string $bytes = M3C_PDF_BYTES): File
{
    $file = File::factory()->create(['mime' => $mime, 'original_name' => $name, 'owner_user_id' => $report->submitted_by]);
    Storage::disk('local')->put($file->path, $bytes);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $file->getKey(), 'kind' => ReportFileKind::Sertifikat]);

    return $file;
}

function m3cInline(File $file, string $route = 'files.show'): string
{
    return route($route, ['file' => $file, FileController::INLINE_PARAMETER => 1]);
}

// Inline PDF ----------------------------------------------------------------------------------------

it('sends a PDF inline for the popup: framable by this origin only, no sandbox, never cached, audited as inline', function (): void {
    $file = m3cEvidence($this->report);

    $response = $this->actingAs($this->kesiswaan)->get(m3cInline($file));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'");

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')->toContain('sertifikat-okn-undip-2026.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public')
        ->and($response->headers->all('x-frame-options'))->toHaveCount(1)
        ->and($response->headers->all('content-security-policy'))->toHaveCount(1)
        ->and($response->streamedContent())->toBe(M3C_PDF_BYTES);

    $log = Activity::query()->where('event', 'file.downloaded')->get();

    expect($log)->toHaveCount(1)
        ->and($log->first()->properties['inline'])->toBeTrue()
        ->and($log->first()->properties['purpose'])->toBe(FilePurpose::ReportEvidence->value)
        ->and($log->first()->causer_id)->toBe($this->kesiswaan->getKey());
});

it('keeps the download as it was without the inline flag', function (): void {
    $file = m3cEvidence($this->report);

    $response = $this->actingAs($this->kesiswaan)->get(route('files.show', $file));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and(Activity::query()->where('event', 'file.downloaded')->sole()->properties->has('inline'))->toBeFalse();
});

it('treats a false inline flag as a download', function (string $value): void {
    $file = m3cEvidence($this->report);

    $response = $this->actingAs($this->kesiswaan)->get(route('files.show', ['file' => $file, 'tampil' => $value]));

    $response->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with(['0', 'tidak', '']);

// Images and everything else ------------------------------------------------------------------------

it('shows a re-encoded image inline but never lets it be framed', function (string $mime): void {
    $file = m3cEvidence($this->report, $mime, 'foto-penyerahan-piala.jpg', 'gambar');

    $response = $this->actingAs($this->kesiswaan)->get(m3cInline($file));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(FileController::previewType($file))->toBe('image')
        ->and(FileController::previewUrl($file))->toBe(m3cInline($file));
})->with(['image/jpeg', 'image/png', 'image/webp']);

it('never sends any other type inline, whatever the query says', function (string $mime): void {
    $file = m3cEvidence($this->report, $mime, 'berkas-lain.bin', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $response = $this->actingAs($this->kesiswaan)->get(m3cInline($file));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and(FileController::previewType($file))->toBeNull()
        ->and(FileController::previewUrl($file))->toBeNull()
        ->and(Activity::query()->where('event', 'file.downloaded')->sole()->properties->has('inline'))->toBeFalse();
})->with([
    'svg' => 'image/svg+xml',
    'gif' => 'image/gif',
    'html' => 'text/html',
    'xhtml' => 'application/xhtml+xml',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'unknown' => 'application/octet-stream',
]);

it('names the viewer of a file for the popup', function (): void {
    $pdf = File::factory()->create(['mime' => 'Application/PDF']);

    expect(FileController::previewType($pdf))->toBe('pdf')
        ->and(FileController::previewUrl($pdf))->toBe(route('files.show', $pdf).'?tampil=1')
        ->and(FileController::previewType(File::factory()->image()->create()))->toBe('image');
});

// Not yours -----------------------------------------------------------------------------------------

it('answers 404 for a file that is not yours, inline or not, and writes no audit row', function (): void {
    $file = m3cEvidence($this->report);

    foreach ([route('files.show', $file), m3cInline($file)] as $url) {
        $response = $this->actingAs($this->dimas->user)->get($url);

        // The 404 page is an ordinary page: it must not inherit the frame permission of the inline answer.
        $response->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
        expect((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
            ->and((string) $response->headers->get('Content-Disposition'))->toBe('');

        $this->actingAs($this->dimas->user)->head($url)->assertNotFound();
    }

    expect(Activity::query()->where('log_name', 'file')->count())->toBe(0);

    // The owner and the verifier do get it.
    $this->actingAs($this->nadia->user)->get(m3cInline($file))->assertOk();
    $this->actingAs($this->kesiswaan)->get(m3cInline($file))->assertOk();
});

it('answers 404 inline when the bytes are gone or the id is unknown', function (): void {
    $file = m3cEvidence($this->report);
    Storage::disk('local')->delete($file->path);

    $this->actingAs($this->kesiswaan)->get(m3cInline($file))->assertNotFound();
    $this->actingAs($this->kesiswaan)->get('/berkas/01ARZ3NDEKTSV4RRFFQ69G5FAV?tampil=1')->assertNotFound();
    expect(Activity::query()->where('log_name', 'file')->count())->toBe(0);
});

it('sends a guest to the login page instead of a file', function (): void {
    $file = m3cEvidence($this->report);

    $this->get(m3cInline($file))->assertRedirect(route('login'));
    $this->head(m3cInline($file))->assertRedirect(route('login'));
});

it('never serves the signature image inline to someone without signature.manage', function (): void {
    $file = File::factory()->purpose(FilePurpose::Signature)->create(['mime' => 'image/png', 'owner_user_id' => null]);
    Storage::disk('local')->put($file->path, 'png');

    $this->actingAs($this->kesiswaan)->get(m3cInline($file))->assertNotFound();
    $this->actingAs($this->nadia->user)->get(m3cInline($file))->assertNotFound();
});

// HEAD ----------------------------------------------------------------------------------------------

it('answers the popup\'s HEAD question with the same headers, no bytes and no audit row', function (): void {
    $file = m3cEvidence($this->report);

    $inline = $this->actingAs($this->kesiswaan)->head(m3cInline($file));

    $inline->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'");
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($inline->headers->get('Cache-Control'))->toContain('no-store')
        ->and($inline->streamedContent())->toBe('');

    $download = $this->actingAs($this->kesiswaan)->head(route('files.show', $file));

    $download->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($download->streamedContent())->toBe('')
        ->and(Activity::query()->where('log_name', 'file')->count())->toBe(0);
});

// Public route --------------------------------------------------------------------------------------

it('never makes a file of the public route framable', function (string $mime, string $disposition): void {
    $registration = Registration::factory()->create(['leader_student_id' => $this->nadia->getKey()]);
    $registration->members()->make(['student_id' => $this->dimas->getKey(), 'invited_at' => now()])
        ->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
    $report = Report::factory()->verified()->create(['registration_id' => $registration->getKey()]);
    $file = File::factory()->purpose(FilePurpose::ReportEvidence)->create(['mime' => $mime, 'owner_user_id' => $this->nadia->user_id]);
    Storage::disk('local')->put($file->path, M3C_PDF_BYTES);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $file->getKey(), 'kind' => ReportFileKind::Sertifikat]);
    Student::query()->whereKey([$this->nadia->getKey(), $this->dimas->getKey()])->update(['publish_consent' => true]);
    Setting::put(SettingKey::PublicCertificates, true);

    $response = $this->get(m3cInline($file, 'files.public'));

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Content-Disposition'))->toStartWith($disposition);
})->with([
    'pdf stays a download' => ['application/pdf', 'attachment'],
    'image stays inline as before' => ['image/jpeg', 'inline'],
]);

// Route and page headers ----------------------------------------------------------------------------

it('keeps login, activation, staff 2FA and the throttle on the file route', function (): void {
    $middleware = Route::getRoutes()->getByName('files.show')->gatherMiddleware();

    expect($middleware)->toContain('auth')->toContain('active')->toContain('activated')->toContain('staff.2fa')->toContain('throttle:60,1')
        ->and(Route::getRoutes()->getByName('files.public')->gatherMiddleware())->toContain('throttle:30,1');
});

it('counts a preview against the same limit as a download', function (): void {
    $file = m3cEvidence($this->report);

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($this->kesiswaan)->head(m3cInline($file))->assertOk();
    }

    $this->actingAs($this->kesiswaan)->get(m3cInline($file))->assertStatus(429);
    $this->actingAs($this->kesiswaan)->get(route('files.show', $file))->assertStatus(429);
});

it('lets a page frame this origin only and never be framed itself', function (): void {
    $response = $this->actingAs($this->kesiswaan)->get(route('kesiswaan.verifikasi.index'));

    $response->assertOk()->assertHeader('X-Frame-Options', 'DENY');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toMatch("/(^|; )frame-src 'self'(;|$)/")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval')
        ->not->toContain('sandbox');
});
