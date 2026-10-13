<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Domain\Files\CoverThumbnail;
use App\Domain\Files\StoreUpload;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\FileController;
use App\Models\File;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| UI rework · cover pictures of a lomba on cards (FileController::cover, App\Domain\Files\CoverThumbnail; SECURITY §3d).
| `files.cover` (/berkas/sampul/{file}) sends a small JPEG of a cover for <img>: at most 640 px, made on the first
| request and kept on the private disk, cached by the browser (private, one day, ETag → 304) and NOT audited. It is
| stricter than a download in what it sends (covers only, never the original) and the same in who gets it:
| signed in + activated (+ staff 2FA), FilePolicy::view, 404 for everything else.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
});

/** A cover stored the way the Kelola lomba form stores it (StoreUpload: re-encoded, at most 2048 px). */
function sampulUpload(User $owner, int $width = 1600, int $height = 2000, string $name = 'poster-nsif-2026.jpg'): File
{
    return app(StoreUpload::class)->store(UploadedFile::fake()->image($name, $width, $height), FilePurpose::Cover, $owner);
}

/** The cover of a published lomba, uploaded by Kesiswaan. */
function sampulPublished(object $t, int $width = 1600, int $height = 2000, string $name = 'poster-nsif-2026.jpg'): File
{
    $file = sampulUpload($t->kesiswaan, $width, $height, $name);
    F::openCompetition(['cover_file_id' => $file->getKey(), 'submitted_by' => $t->kesiswaan->getKey()]);

    return $file;
}

function sampulJpeg(int $width = 40, int $height = 50): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 40));
    ob_start();
    imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

// The picture ---------------------------------------------------------------------------------------

it('sends a student the small JPEG of a published lomba\'s cover and lets the browser keep it', function (): void {
    $file = sampulPublished($this);

    $response = $this->actingAs(F::user($this->nadia))->get(route('files.cover', $file));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('ETag', '"'.CoverThumbnail::etag($file).'"');

    $bytes = (string) $response->getContent();
    [$width, $height, $type] = getimagesizefromstring($bytes);

    expect($type)->toBe(IMAGETYPE_JPEG)
        ->and([$width, $height])->toBe([512, 640]) // 1600 × 2000 keeps its shape
        ->and(max($width, $height))->toBeLessThanOrEqual(CoverThumbnail::MAX_SIDE)
        ->and(str_contains($bytes, "\xFF\xC2"))->toBeTrue() // progressive JPEG (SOF2)
        ->and(str_contains($bytes, 'Exif'))->toBeFalse()
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
        ->and(strlen($bytes))->toBeLessThan($file->size)
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=86400')
        ->not->toContain('public')->not->toContain('no-store')->not->toContain('no-cache')
        ->and($response->headers->all('content-security-policy'))->toHaveCount(1);

    // Kept on the private disk under sampul/<file id>.jpg, no temporary file left, nothing on the public disk.
    expect(CoverThumbnail::path($file))->toBe('sampul/'.$file->getKey().'.jpg')
        ->and(Storage::disk('local')->get(CoverThumbnail::path($file)))->toBe($bytes)
        ->and(Storage::disk('local')->files('sampul'))->toBe(['sampul/'.$file->getKey().'.jpg'])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    // The stored original is untouched.
    expect(hash('sha256', (string) Storage::disk('local')->get($file->path)))->toBe($file->sha256);
});

it('keeps the shape of the cover and never enlarges it', function (int $w, int $h, string $name, array $expected): void {
    $file = sampulPublished($this, $w, $h, $name);

    $response = $this->actingAs(F::user($this->nadia))->get(route('files.cover', $file))->assertOk();
    [$width, $height, $type] = getimagesizefromstring((string) $response->getContent());

    expect([$width, $height])->toBe($expected)
        ->and($type)->toBe(IMAGETYPE_JPEG); // whatever the original is: PNG and WEBP become JPEG
})->with([
    'portrait jpg' => [1200, 1500, 'poster.jpg', [512, 640]],
    'landscape, stored at 2048 px' => [3000, 1500, 'spanduk.jpg', [640, 320]],
    'small png stays small' => [300, 400, 'poster-kecil.png', [300, 400]],
    'square webp' => [1400, 1400, 'poster.webp', [640, 640]],
]);

it('makes the picture once and sends the kept one afterwards', function (): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);
    $path = CoverThumbnail::path($file);

    $first = (string) $this->actingAs($user)->get(route('files.cover', $file))->assertOk()->getContent();

    // Another JPEG at the kept path is sent as it is: nothing is made again.
    $marker = sampulJpeg();
    Storage::disk('local')->put($path, $marker);

    expect((string) $this->actingAs($user)->get(route('files.cover', $file))->assertOk()->getContent())->toBe($marker);

    // Lost or damaged: made again from the original, the same picture as before.
    foreach (['bukan gambar', ''] as $damaged) {
        Storage::disk('local')->put($path, $damaged);

        expect((string) $this->actingAs($user)->get(route('files.cover', $file))->assertOk()->getContent())->toBe($first)
            ->and(Storage::disk('local')->get($path))->toBe($first);
    }

    Storage::disk('local')->deleteDirectory(CoverThumbnail::DIRECTORY);

    expect((string) $this->actingAs($user)->get(route('files.cover', $file))->assertOk()->getContent())->toBe($first)
        ->and(Storage::disk('local')->files(CoverThumbnail::DIRECTORY))->toBe([$path]);
});

it('answers 304 to If-None-Match without reading or making the picture', function (): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);
    $url = route('files.cover', $file);

    $etag = (string) $this->actingAs($user)->get($url)->assertOk()->headers->get('ETag');
    Storage::disk('local')->delete(CoverThumbnail::path($file));

    foreach ([$etag, 'W/'.$etag, '"lain", '.$etag] as $validator) {
        $again = $this->actingAs($user)->get($url, ['If-None-Match' => $validator]);

        $again->assertStatus(304)->assertHeader('ETag', $etag)->assertHeader('X-Content-Type-Options', 'nosniff');
        expect((string) $again->getContent())->toBe('')
            ->and($again->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=86400');
    }

    expect(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse();

    // Another validator (the browser holds something else): the whole picture.
    $full = $this->actingAs($user)->get($url, ['If-None-Match' => '"0123456789abcdef0123456789abcdef"'])->assertOk();

    expect((string) $full->getContent())->toStartWith("\xFF\xD8");

    // The validator follows the bytes of the original: other bytes, another validator.
    $file->forceFill(['sha256' => hash('sha256', 'lain')])->save();

    expect('"'.CoverThumbnail::etag($file->fresh()).'"')->not->toBe($etag);
    $this->actingAs($user)->get($url, ['If-None-Match' => $etag])->assertOk();
});

it('answers HEAD with the same headers and no body', function (): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);

    $get = $this->actingAs($user)->get(route('files.cover', $file))->assertOk();
    $head = $this->actingAs($user)->head(route('files.cover', $file));

    $head->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('ETag', (string) $get->headers->get('ETag'))
        ->assertHeader('Content-Length', (string) strlen((string) $get->getContent()));

    expect((string) $head->getContent())->toBe('')
        ->and($head->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=86400');

    $this->actingAs($user)->head(route('files.cover', $file), ['If-None-Match' => (string) $get->headers->get('ETag')])->assertStatus(304);
});

// Who gets it ---------------------------------------------------------------------------------------

it('shows the cover of a published lomba to every signed-in role', function (RoleName $role): void {
    $file = sampulPublished($this);

    $user = $role === RoleName::Siswa ? F::user($this->dimas) : F::staff($role, 'Agus Setiawan, S.Or.');

    $this->actingAs($user)->get(route('files.cover', $file))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
})->with(array_combine(
    array_map(fn (RoleName $role): string => $role->value, RoleName::cases()),
    array_map(fn (RoleName $role): array => [$role], RoleName::cases()),
));

it('keeps the cover of an unpublished usulan to its submitter and the verifiers (404 for everyone else)', function (): void {
    $submitter = F::user($this->nadia);
    $file = sampulUpload($submitter);
    F::pendingCompetition($submitter, ['cover_file_id' => $file->getKey()]);
    $url = route('files.cover', $file);

    $this->actingAs($submitter)->get($url)->assertOk();
    $this->actingAs($this->kesiswaan)->get($url)->assertOk();
    $this->actingAs(F::staff(RoleName::Admin, 'Operator STH'))->get($url)->assertOk();

    $others = [
        F::user($this->dimas),
        F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.'),
        F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.'),
        F::staff(RoleName::TataUsaha, 'Sri Handayani'),
        F::staff(RoleName::Humas, 'Galih Prasetyo'),
    ];

    foreach ($others as $other) {
        $response = $this->actingAs($other)->get($url);

        // An ordinary 404 page: not an image, and nothing a browser would keep for a day.
        $response->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
        expect((string) $response->headers->get('Content-Type'))->toStartWith('text/html')
            ->and((string) $response->headers->get('Cache-Control'))->not->toContain('max-age=86400')
            ->and((string) $response->headers->get('ETag'))->toBe('')
            ->and((string) $response->getContent())->not->toStartWith("\xFF\xD8");

        $this->actingAs($other)->head($url)->assertNotFound();
        // Knowing the validator does not help: the policy is asked before the 304.
        $this->actingAs($other)->get($url, ['If-None-Match' => '"'.CoverThumbnail::etag($file).'"'])->assertNotFound();
    }
});

it('follows the lomba: a closed or rejected one shows its cover to the verifiers and its submitter only', function (CompetitionStatus $status): void {
    $file = sampulUpload($this->kesiswaan);
    F::openCompetition(['cover_file_id' => $file->getKey(), 'submitted_by' => $this->kesiswaan->getKey()])
        ->forceFill(['status' => $status])->save();

    $this->actingAs($this->kesiswaan)->get(route('files.cover', $file))->assertOk();
    $this->actingAs(F::user($this->nadia))->get(route('files.cover', $file))->assertNotFound();
    $this->actingAs(F::staff(RoleName::Guru, 'Maya Anggraini, S.Sn.'))->get(route('files.cover', $file))->assertNotFound();
})->with([
    'ditutup' => [CompetitionStatus::Ditutup],
    'ditolak' => [CompetitionStatus::Ditolak],
]);

it('answers 404 for a file that is not a cover, even one the user may download', function (): void {
    $juknis = app(StoreUpload::class)->store(UploadedFile::fake()->image('brosur-nsif-2026.jpg', 800, 1000), FilePurpose::Juknis, $this->kesiswaan);
    // Even when a lomba points at it as its cover: the purpose of the file decides.
    F::openCompetition(['juknis_file_id' => $juknis->getKey(), 'cover_file_id' => $juknis->getKey()]);
    $user = F::user($this->nadia);

    $this->actingAs($user)->get(route('files.show', $juknis))->assertOk();
    $this->actingAs($user)->get(route('files.cover', $juknis))->assertNotFound();
    $this->actingAs($user)->head(route('files.cover', $juknis))->assertNotFound();
    $this->actingAs($this->kesiswaan)->get(route('files.cover', $juknis))->assertNotFound(); // its uploader too

    expect(CoverThumbnail::bytes($juknis))->toBeNull()
        ->and(Storage::disk('local')->exists(CoverThumbnail::path($juknis)))->toBeFalse()
        ->and(Storage::disk('local')->directories())->not->toContain(CoverThumbnail::DIRECTORY);
});

it('never makes a picture of a file with another purpose, whoever asks', function (FilePurpose $purpose): void {
    $admin = F::staff(RoleName::Admin, 'Operator STH');
    $file = File::factory()->image()->purpose($purpose)->create(['owner_user_id' => $admin->getKey()]);
    Storage::disk('local')->put($file->path, sampulJpeg(400, 500));

    $this->actingAs($admin)->get(route('files.cover', $file))->assertNotFound();

    expect(CoverThumbnail::bytes($file))->toBeNull()
        ->and(Storage::disk('local')->directories())->not->toContain(CoverThumbnail::DIRECTORY);
})->with(collect(FilePurpose::cases())
    ->reject(fn (FilePurpose $purpose): bool => $purpose === FilePurpose::Cover)
    ->mapWithKeys(fn (FilePurpose $purpose): array => [$purpose->value => [$purpose]])
    ->all());

it('answers 404 when the original is gone, is not an image or is larger than an upload can be', function (string $case): void {
    $file = File::factory()->image()->purpose(FilePurpose::Cover)->create(['owner_user_id' => $this->kesiswaan->getKey()]);
    F::openCompetition(['cover_file_id' => $file->getKey()]);

    match ($case) {
        'gone' => null,
        'text' => Storage::disk('local')->put($file->path, 'bukan gambar'),
        'empty' => Storage::disk('local')->put($file->path, ''),
        'svg' => Storage::disk('local')->put($file->path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'gif' => Storage::disk('local')->put($file->path, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')),
        'cut off' => Storage::disk('local')->put($file->path, substr(sampulJpeg(400, 500), 0, 300)),
        'too large' => Storage::disk('local')->put($file->path, sampulJpeg(StoreUpload::MAX_IMAGE_SIDE + 1, 10)),
    };

    $this->actingAs(F::user($this->nadia))->get(route('files.cover', $file))->assertNotFound();
    $this->actingAs(F::user($this->nadia))->head(route('files.cover', $file))->assertNotFound();
    $this->actingAs($this->kesiswaan)->get('/berkas/sampul/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound(); // unknown id

    expect(Storage::disk('local')->files(CoverThumbnail::DIRECTORY))->toBe([]);
})->with(['gone', 'text', 'empty', 'svg', 'gif', 'cut off', 'too large']);

it('sends a guest to the login page instead of a picture', function (): void {
    $file = sampulPublished($this);

    $this->get(route('files.cover', $file))->assertRedirect(route('login'));
    $this->head(route('files.cover', $file))->assertRedirect(route('login'));
    $this->get(route('files.cover', $file), ['If-None-Match' => '"'.CoverThumbnail::etag($file).'"'])->assertRedirect(route('login'));

    expect(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse();
});

it('serves nothing to an account that is disabled, archived or frozen', function (string $case): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);

    $user->forceFill(match ($case) {
        'disabled' => ['status' => UserStatus::Disabled],
        'archived' => ['status' => UserStatus::Archived],
        'frozen' => ['frozen_at' => now(), 'frozen_reason' => 'not_me'],
    })->save();

    $response = $this->actingAs($user->fresh())->get(route('files.cover', $file));

    $response->assertRedirect(route('login'));
    $this->assertGuest();

    expect((string) $response->getContent())->not->toStartWith("\xFF\xD8")
        ->and(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse();
})->with(['disabled', 'archived', 'frozen']);

it('sends an account that has not finished activation, and staff without 2FA, somewhere else first', function (): void {
    $file = sampulPublished($this);

    // An imported student before the first login: no email bound yet.
    $pending = Student::factory()->create(['classroom_id' => $this->nadia->classroom_id]);
    $this->actingAs($pending->user->fresh())->get(route('files.cover', $file))->assertRedirect(route('aktivasi'));

    config(['fortify.sth.require_staff_2fa' => true]);
    $this->actingAs($this->kesiswaan)->get(route('files.cover', $file))->assertRedirect(route('two-factor.setup'));

    expect(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse();
});

// Not a download ------------------------------------------------------------------------------------

it('writes no audit row for a cover picture, while a download of the same file is still audited', function (): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);
    $url = route('files.cover', $file);
    $rows = DB::table('activity_log')->count();

    $etag = (string) $this->actingAs($user)->get($url)->assertOk()->headers->get('ETag'); // made
    $this->actingAs($user)->get($url)->assertOk(); // kept
    $this->actingAs($user)->head($url)->assertOk();
    $this->actingAs($user)->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    $this->actingAs($this->kesiswaan)->get($url)->assertOk();
    $this->actingAs($user)->get(route('files.cover', File::factory()->create()))->assertNotFound();

    expect(DB::table('activity_log')->count())->toBe($rows)
        ->and(Activity::query()->where('log_name', 'file')->count())->toBe(0);

    // The original itself only leaves through files.show, as an audited download.
    $this->actingAs($user)->get(route('files.show', $file))->assertOk();

    expect(Activity::query()->where('event', 'file.downloaded')->count())->toBe(1)
        ->and(DB::table('activity_log')->count())->toBe($rows + 1);
});

// Clean up ------------------------------------------------------------------------------------------

it('removes the kept picture when the original cover is discarded', function (): void {
    $file = sampulPublished($this);
    $other = sampulPublished($this, 800, 1000, 'poster-ldbi-2026.jpg');
    $user = F::user($this->nadia);

    $this->actingAs($user)->get(route('files.cover', $file))->assertOk();
    $this->actingAs($user)->get(route('files.cover', $other))->assertOk();

    expect(Storage::disk('local')->files(CoverThumbnail::DIRECTORY))->toHaveCount(2);

    app(StoreUpload::class)->discard($file);

    expect(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse()
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse()
        ->and(File::query()->whereKey($file->getKey())->exists())->toBeFalse()
        // The picture of another cover stays.
        ->and(Storage::disk('local')->files(CoverThumbnail::DIRECTORY))->toBe([CoverThumbnail::path($other)]);

    $this->actingAs($user)->get(route('files.cover', $file->getKey()))->assertNotFound();
    $this->actingAs($user)->get(route('files.cover', $other))->assertOk();
});

it('discards a file that never had a picture, and files of other purposes, as before', function (): void {
    $cover = sampulUpload($this->kesiswaan);
    $juknis = app(StoreUpload::class)->store(KesiswaanFixtures::pdf(), FilePurpose::Juknis, $this->kesiswaan);

    app(StoreUpload::class)->discard($cover);
    app(StoreUpload::class)->discard($juknis);

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('stops showing a replaced or removed cover to students, and shows the new one', function (): void {
    KesiswaanFixtures::boot();
    $student = F::user($this->nadia);

    // Only siswa and Admin add a lomba (D-145); Kesiswaan replaces the cover below.
    $competition = app(CreateCompetition::class)->handle(
        F::staff(RoleName::Admin, 'Operator STH'),
        KesiswaanFixtures::form(),
        null,
        UploadedFile::fake()->image('poster-nsif-2026.jpg', 1200, 1500),
    );
    $competition->forceFill(['status' => CompetitionStatus::Dipublikasikan])->save();
    $first = File::query()->findOrFail($competition->cover_file_id);

    expect($first->purpose)->toBe(FilePurpose::Cover)
        ->and(FileController::coverUrl($competition->cover_file_id))->toBe(route('files.cover', $first));

    $this->actingAs($student)->get(route('files.cover', $first))->assertOk();

    // Replaced: a new File, so a new address. The old one is no cover of any lomba any more.
    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), KesiswaanFixtures::form(), null, UploadedFile::fake()->image('poster-baru.png', 600, 750));
    $second = File::query()->findOrFail($competition->fresh()->cover_file_id);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(FileController::coverUrl($competition->fresh()->cover_file_id))->toBe(route('files.cover', $second));

    $this->actingAs($student)->get(route('files.cover', $second))->assertOk();
    $this->actingAs($student)->get(route('files.cover', $first))->assertNotFound();

    // Removed: no address at all, and the file is no longer shown either.
    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), KesiswaanFixtures::form(), null, null, true);

    expect($competition->fresh()->cover_file_id)->toBeNull()
        ->and(FileController::coverUrl($competition->fresh()->cover_file_id))->toBeNull();

    $this->actingAs($student)->get(route('files.cover', $second))->assertNotFound();
});

// Address and route ---------------------------------------------------------------------------------

it('builds the address of a cover from the file id, and none without a cover', function (): void {
    $file = File::factory()->image()->purpose(FilePurpose::Cover)->create();

    expect(FileController::coverUrl($file->getKey()))->toBe(route('files.cover', $file))
        ->and(FileController::coverUrl($file->getKey()))->toEndWith('/berkas/sampul/'.$file->getKey())
        ->and(FileController::coverUrl(null))->toBeNull()
        ->and(FileController::coverUrl(''))->toBeNull();
});

it('keeps login, activation, staff 2FA and a throttle of its own on the cover route', function (): void {
    $route = Route::getRoutes()->getByName('files.cover');

    expect($route->uri())->toBe('berkas/sampul/{file}')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('web', 'auth', 'active', 'activated', 'staff.2fa', 'throttle:240,1,berkas-sampul')
        ->and($route->gatherMiddleware())->not->toContain('throttle:60,1,berkas');
});

it('counts cover pictures on their own: a page of cards never uses up the download limit, and 240 a minute is the end', function (): void {
    $file = sampulPublished($this);
    $user = F::user($this->nadia);
    $url = route('files.cover', $file);

    $etag = (string) $this->actingAs($user)->get($url)->assertOk()->headers->get('ETag');

    for ($i = 1; $i < 240; $i++) {
        $this->actingAs($user)->get($url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    $this->actingAs($user)->get($url)->assertStatus(429);
    $this->actingAs($user)->get($url, ['If-None-Match' => $etag])->assertStatus(429);

    // Downloads have their own counter, and so has every other user.
    $this->actingAs($user)->get(route('files.show', $file))->assertOk();
    $this->actingAs(F::user($this->dimas))->get($url)->assertOk();
});
