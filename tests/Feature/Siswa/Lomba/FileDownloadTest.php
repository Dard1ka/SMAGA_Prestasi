<?php

declare(strict_types=1);

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\MemberRole;
use App\Enums\ReportFileKind;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
});

function w2a1StoredPdf(User $owner, FilePurpose $purpose = FilePurpose::Juknis): File
{
    $pdf = UploadedFile::fake()->createWithContent('juknis-nsif-2026.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");

    return app(StoreUpload::class)->store($pdf, $purpose, $owner);
}

it('streams a published juknis to any student with safe headers and audits it', function (): void {
    $file = w2a1StoredPdf(F::staff(RoleName::Kesiswaan));
    F::openCompetition(['juknis_file_id' => $file->getKey()]);

    $response = $this->actingAs(F::user($this->nadia))->get(route('files.show', $file));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(Activity::query()->where('event', 'file.downloaded')->count())->toBe(1);
});

it('keeps a pending proposal brochure private to its submitter and verifiers (404 for others)', function (): void {
    $file = w2a1StoredPdf(F::user($this->nadia));
    F::pendingCompetition(F::user($this->nadia), ['juknis_file_id' => $file->getKey()]);

    $this->actingAs(F::user($this->nadia))->get(route('files.show', $file))->assertOk();
    $this->actingAs(F::staff(RoleName::Kesiswaan))->get(route('files.show', $file))->assertOk();
    $this->actingAs(F::user($this->dimas))->get(route('files.show', $file))->assertNotFound();
});

it('never serves the signature image to anyone but signature.manage', function (): void {
    $file = File::factory()->purpose(FilePurpose::Signature)->create(['owner_user_id' => null]);
    Storage::disk('local')->put($file->path, 'png');

    $this->actingAs(F::user($this->nadia))->get(route('files.show', $file))->assertNotFound();
    $this->actingAs(F::staff(RoleName::TataUsaha))->get(route('files.show', $file))->assertNotFound();
});

it('requires login and an activated account', function (): void {
    $file = w2a1StoredPdf(F::user($this->nadia));

    $this->get(route('files.show', $file))->assertRedirect(route('login'));
});

it('re-encodes images and drops metadata', function (): void {
    $jpeg = UploadedFile::fake()->image('foto.jpg', 3000, 1500);

    $file = app(StoreUpload::class)->store($jpeg, FilePurpose::Juknis, F::user($this->nadia));

    $bytes = Storage::disk('local')->get($file->path);
    [$w, $h] = getimagesizefromstring($bytes);
    expect($file->mime)->toBe('image/jpeg')
        ->and(max($w, $h))->toBeLessThanOrEqual(StoreUpload::MAX_IMAGE_SIDE)
        ->and($file->sha256)->toBe(hash('sha256', $bytes))
        ->and(str_contains($bytes, 'Exif'))->toBeFalse();
});

it('rejects disguised or dangerous files', function (string $name, string $content, string $message): void {
    $upload = UploadedFile::fake()->createWithContent($name, $content);

    try {
        app(StoreUpload::class)->store($upload, FilePurpose::Juknis, F::user($this->nadia));
        $this->fail('Expected InvalidUpload.');
    } catch (InvalidUpload $e) {
        expect(implode(' ', $e->errors))->toContain($message);
    }

    expect(File::query()->count())->toBe(0);
})->with([
    'html named pdf' => ['brosur.pdf', '<html><script>alert(1)</script></html>', 'Isi file'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Jenis file'],
    'pdf with javascript' => ['a.pdf', "%PDF-1.4\n<< /S /JavaScript /JS (app.alert(1)) >>\n%%EOF", 'skrip'],
    'pdf with hex-escaped name' => ['b.pdf', "%PDF-1.4\n<< /J#61vaScript 3 0 R >>\n%%EOF", 'skrip'],
    'pdf with embedded file' => ['c.pdf', "%PDF-1.4\n<< /EmbeddedFiles 4 0 R >>\n%%EOF", 'skrip'],
]);

it('ignores binary stream bodies when scanning a PDF', function (): void {
    $noise = "/JS\x00/AA\x01";
    $pdf = "%PDF-1.4\n1 0 obj << /Length ".strlen($noise)." >>\nstream\n{$noise}\nendstream\nendobj\n%%EOF";

    expect(StoreUpload::pdfHasActiveContent($pdf))->toBeFalse()
        ->and(StoreUpload::pdfHasActiveContent("%PDF-1.5\n<< /Type /ObjStm /Filter /FlateDecode >>\nstream\n".gzcompress('<< /OpenAction 5 0 R >>')."\nendstream"))->toBeTrue();
});

it('shares Riwayat talenta certificates with the student, Kesiswaan and the own pembina only', function (): void {
    $pembina = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $otherGuru = F::staff(RoleName::Guru, 'Agus Setiawan, S.Or.');
    $profile = TalentProfile::factory()->create(['student_id' => $this->nadia->getKey()]);
    TalentApplication::factory()->create(['student_id' => $this->nadia->getKey()])->forceFill(['mentor_id' => $pembina->id])->save();
    $file = File::factory()->purpose(FilePurpose::TalentEvidence)->create(['owner_user_id' => null]);
    Storage::disk('local')->put($file->path, '%PDF-1.4');
    $profile->certificates()->attach($file->getKey(), ['id' => (string) Str::ulid()]);

    $this->actingAs(F::user($this->nadia))->get(route('files.show', $file))->assertOk();
    $this->actingAs(F::staff(RoleName::Kesiswaan))->get(route('files.show', $file))->assertOk();
    $this->actingAs($pembina)->get(route('files.show', $file))->assertOk();
    $this->actingAs($otherGuru)->get(route('files.show', $file))->assertNotFound();
    $this->actingAs(F::user($this->dimas))->get(route('files.show', $file))->assertNotFound();
});

it('serves a sent siswa letter to confirmed members through LetterPolicy and hides it from others', function (): void {
    $registration = Registration::factory()->create(['leader_student_id' => $this->nadia->getKey()]);
    $file = File::factory()->purpose(FilePurpose::Letter)->create(['owner_user_id' => null]);
    Storage::disk('local')->put($file->path, '%PDF-1.4');
    Letter::factory()->create(['registration_id' => $registration->getKey(), 'type' => LetterType::Siswa, 'file_id' => $file->getKey()])
        ->forceFill(['sent_at' => now()])->save();

    $this->actingAs(F::user($this->nadia))->get(route('files.show', $file))->assertOk();
    $this->actingAs(F::user($this->dimas))->get(route('files.show', $file))->assertNotFound();
});

/** A verified team report (Nadia ketua + Dimas) with one certificate file; returns [file, registration]. */
function w2a1PublicCertificate(object $t, ReportFileKind $kind = ReportFileKind::Sertifikat, bool $verified = true): array
{
    $registration = Registration::factory()->create(['leader_student_id' => $t->nadia->getKey()]);
    $registration->members()->make(['student_id' => $t->dimas->getKey(), 'invited_at' => now()])
        ->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
    $report = $verified
        ? Report::factory()->verified()->create(['registration_id' => $registration->getKey()])
        : Report::factory()->create(['registration_id' => $registration->getKey()]);
    $file = File::factory()->purpose(FilePurpose::ReportEvidence)->create(['owner_user_id' => $t->nadia->user_id]);
    Storage::disk('local')->put($file->path, '%PDF-1.4');
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $file->getKey(), 'kind' => $kind]);

    return [$file, $registration];
}

it('serves a verified certificate to guests only while every member is named and the Admin switch is on', function (): void {
    [$file] = w2a1PublicCertificate($this);

    $this->get(route('files.public', $file))->assertNotFound(); // switch off (default)

    Setting::put(SettingKey::PublicCertificates, true);
    $this->get(route('files.public', $file))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(Activity::query()->where('event', 'file.public_downloaded')->whereNull('causer_id')->exists())->toBeTrue();

    // D-162: no consent any more; only a disabled (or deleted) member hides the team certificate.
    User::query()->whereKey($this->dimas->user_id)->update(['status' => UserStatus::Disabled->value]);
    $this->get(route('files.public', $file))->assertNotFound();

    // The authenticated route keeps its own rules: guests are sent to login.
    $this->get(route('files.show', $file))->assertRedirect(route('login'));
});

it('never serves other files publicly', function (string $case): void {
    Setting::put(SettingKey::PublicCertificates, true);

    $file = match ($case) {
        'photo' => w2a1PublicCertificate($this, ReportFileKind::Foto)[0],
        'unverified report' => w2a1PublicCertificate($this, verified: false)[0],
        'published juknis' => tap(w2a1StoredPdf(F::staff(RoleName::Kesiswaan)), fn ($f) => F::openCompetition(['juknis_file_id' => $f->getKey()])),
        'signature' => tap(File::factory()->purpose(FilePurpose::Signature)->create(), fn ($f) => Storage::disk('local')->put($f->path, 'png')),
    };

    $this->get(route('files.public', $file))->assertNotFound();
    $this->actingAs(F::user($this->nadia))->get(route('files.public', $file))->assertNotFound();
})->with(['photo', 'unverified report', 'published juknis', 'signature']);
