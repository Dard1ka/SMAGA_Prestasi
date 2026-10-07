<?php

declare(strict_types=1);

use App\Domain\Files\CoverThumbnail;
use App\Domain\Files\StoreUpload;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Http\Controllers\FileController;
use App\Models\Competition;
use App\Models\File;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\Demo\DemoFileRefresher;
use Database\Seeders\Support\DemoCoverArt;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| UI rework · cover pictures of the demo lomba (Database\Seeders\Support\DemoCoverArt, DemoFiles::cover(), D-106).
| - The demo seeder gives seven published lomba of all four bidang a cover. In the test suite it stays the tiny grey
|   placeholder, as the demo PDFs stay placeholders; on a real local seed DemoFileRefresher draws the posters.
| - `sth:demo:refresh-files` draws a poster of 960 × 1200 over the file at the same path (JPEG, CONTOH in its
|   comment and on the picture), changes nothing on a second run, and makes the app forget the small picture it
|   kept of the old bytes. `--lengkapi` adds the covers an older demo database lacks.
| - Nothing of this runs outside APP_ENV=local, and no poster is ever drawn in production.
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    DemoFiles::forceRealistic(null);
    app()['env'] = 'testing';
});

/** Seed the local demo data the way the other demo tests do (the seeder refuses any other environment). */
function sampulSeedDemo(): void
{
    app()['env'] = 'local';
    test()->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';
}

/** Run the refresh command as on a developer's machine; returns what it printed. */
function sampulRefresh(array $options): string
{
    app()['env'] = 'local';

    try {
        expect(Artisan::call('sth:demo:refresh-files', $options))->toBe(0);

        return Artisan::output();
    } finally {
        app()['env'] = 'testing';
    }
}

/** What a refresh of the covers must leave alone: the PDFs of the demo data and every other table. */
function sampulUntouched(): array
{
    return [
        File::query()->where('path', 'like', 'demo/%')->where('mime', 'application/pdf')->orderBy('id')->pluck('sha256', 'id')->all(),
        DB::table('competitions')->count(),
        DB::table('competitions')->max('updated_at'),
        DB::table('competitions')->whereNotNull('cover_file_id')->orderBy('id')->pluck('cover_file_id', 'id')->all(),
        DB::table('activity_log')->count(),
    ];
}

it('gives seven published demo lomba of all four bidang a cover: the tiny placeholder in the test suite', function (): void {
    // The small picture of a cover of an earlier seed is an orphan after migrate:fresh.
    Storage::disk('local')->put('sampul/01arz3ndektsv4rrffq69g5fav.jpg', 'sisa');

    sampulSeedDemo();

    $covered = Competition::query()->whereNotNull('cover_file_id')->with('coverFile')->orderBy('name')->get();

    expect($covered)->toHaveCount(7)
        ->and($covered->pluck('name')->all())->toEqualCanonicalizing(DemoCoverArt::competitions())
        ->and($covered->every(fn (Competition $c): bool => $c->status === CompetitionStatus::Dipublikasikan))->toBeTrue()
        ->and($covered->map(fn (Competition $c): string => $c->bidang->value)->unique()->sort()->values()->all())->toBe(['olahraga', 'riset', 'sains', 'seni_budaya'])
        ->and($covered->filter(fn (Competition $c): bool => str_contains($c->name, 'NSIF')))->toHaveCount(1)
        ->and($covered->filter(fn (Competition $c): bool => str_contains($c->name, 'LDBI')))->toHaveCount(1)
        // One published lomba has none, so the catalog also shows a card without a cover.
        ->and(Competition::query()->published()->whereNull('cover_file_id')->pluck('name')->all())->toBe(['KRENOVA Kota Semarang 2027'])
        ->and(Storage::disk('local')->files(CoverThumbnail::DIRECTORY))->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    foreach ($covered as $competition) {
        $file = $competition->coverFile;
        $bytes = (string) Storage::disk('local')->get($file->path);
        [$width, $height, $type] = getimagesizefromstring($bytes);

        expect($file->purpose)->toBe(FilePurpose::Cover)
            ->and($file->mime)->toBe('image/jpeg')
            ->and($file->disk)->toBe('local')
            ->and($file->path)->toMatch('~^demo/[a-z0-9]{26}\.jpg$~')
            ->and($file->original_name)->toBe(DemoCoverArt::fileNameFor($competition->name))->toStartWith('sampul-')
            ->and($file->size)->toBe(strlen($bytes))->toBeLessThan(2048)
            ->and($file->sha256)->toBe(hash('sha256', $bytes))
            ->and($file->owner_user_id)->toBe($competition->submitted_by)
            ->and($bytes)->toBe(DemoCoverArt::placeholder())
            ->and([$width, $height, $type])->toBe([16, 20, IMAGETYPE_JPEG]);
    }
});

it('draws a poster of 960 × 1200 over every demo cover, each one different, and changes nothing on a second run', function (): void {
    sampulSeedDemo();

    $disk = Storage::disk('local');
    $before = File::query()->where('purpose', FilePurpose::Cover)->get()->keyBy('id');
    $paths = $disk->allFiles('demo');
    $untouched = sampulUntouched();

    $output = sampulRefresh(['--berkas' => ['sampul-*']]);
    $after = File::query()->where('purpose', FilePurpose::Cover)->get()->keyBy('id');

    expect($output)->toContain('Berkas demo di folder demo/: 7', 'dibuat ulang · Sampul lomba: 7')->not->toContain('dilewati', 'gagal')
        ->and($after)->toHaveCount(7)
        ->and($after->pluck('sha256')->unique())->toHaveCount(7) // seven different pictures
        ->and($disk->allFiles('demo'))->toEqualCanonicalizing($paths) // same paths: no new file, no orphan
        ->and(sampulUntouched())->toBe($untouched);

    foreach ($after as $file) {
        $bytes = (string) $disk->get($file->path);
        [$width, $height, $type] = getimagesizefromstring($bytes);
        $spec = DemoCoverArt::describe($file->original_name, Competition::query()->where('cover_file_id', $file->getKey())->firstOrFail());

        expect($file->path)->toBe($before[$file->getKey()]->path)
            ->and([$width, $height, $type])->toBe([DemoCoverArt::WIDTH, DemoCoverArt::HEIGHT, IMAGETYPE_JPEG])
            ->and($width / $height)->toBe(4 / 5)
            ->and($file->size)->toBe(strlen($bytes))->toBeGreaterThan(20 * 1024)->toBeLessThan(300 * 1024)
            ->and($file->sha256)->toBe(hash('sha256', $bytes))
            // Says in the file itself that it is a sample, and what it was drawn from.
            ->and(substr($bytes, 0, 256))->toContain('CONTOH', 'data fiktif', 'bukan poster resmi')
            ->and(DemoCoverArt::hasId($bytes, DemoCoverArt::id($spec)))->toBeTrue()
            ->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and($spec['date'])->not->toBe('')
            ->and($spec['deadline'])->toStartWith('Batas daftar ');
    }

    // A poster is a plain JPEG the app's own upload path takes as a cover.
    $poster = (string) $disk->get($after->first()->path);
    $stored = app(StoreUpload::class)->store(UploadedFile::fake()->createWithContent('sampul.jpg', $poster), FilePurpose::Cover, null);

    expect($stored->mime)->toBe('image/jpeg');
    app(StoreUpload::class)->discard($stored);

    // Second run: every poster is up to date, nothing is written.
    $stamps = File::query()->where('purpose', FilePurpose::Cover)->pluck('updated_at', 'id')->map->toIso8601String()->all();
    $hashes = $after->pluck('sha256', 'id')->all();
    $this->travel(5)->minutes();

    expect(sampulRefresh(['--berkas' => ['sampul-*']]))->toContain('sudah terbaru (tidak diubah): 7')->not->toContain('dibuat ulang')
        ->and(File::query()->where('purpose', FilePurpose::Cover)->pluck('updated_at', 'id')->map->toIso8601String()->all())->toBe($stamps)
        ->and(sampulUntouched())->toBe($untouched);

    // --force draws them again: the same data gives the same bytes.
    expect(sampulRefresh(['--berkas' => ['sampul-*'], '--force' => true]))->toContain('dibuat ulang · Sampul lomba: 7')
        ->and(File::query()->where('purpose', FilePurpose::Cover)->pluck('sha256', 'id')->all())->toBe($hashes);

    // A lomba whose date changes gets another poster; the others stay.
    $osn = Competition::query()->where('name', 'OSN Tingkat Kota Semarang 2026')->firstOrFail();
    DB::table('competitions')->where('id', $osn->getKey())->update(['starts_on' => '2026-10-21', 'final_on' => '2026-10-22']);

    expect(sampulRefresh(['--berkas' => ['sampul-*']]))->toContain('dibuat ulang · Sampul lomba: 1', 'sudah terbaru (tidak diubah): 6')
        ->and(File::query()->findOrFail($osn->cover_file_id)->sha256)->not->toBe($hashes[$osn->cover_file_id]);
});

it('shows the drawn poster through files.cover and forgets the small picture of the placeholder', function (): void {
    sampulSeedDemo();
    config(['fortify.sth.require_staff_2fa' => false]);

    $kesiswaan = User::query()->where('username', 'kesiswaan')->firstOrFail();
    $nsif = Competition::query()->where('name', 'National Science and Invention Fair (NSIF) 2026')->firstOrFail();
    $file = File::query()->findOrFail($nsif->cover_file_id);
    $url = (string) FileController::coverUrl($nsif->cover_file_id);

    // Before the poster is drawn the app shows (and keeps) the placeholder.
    $placeholder = $this->actingAs($kesiswaan)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $etag = (string) $placeholder->headers->get('ETag');

    expect(array_slice((array) getimagesizefromstring((string) $placeholder->getContent()), 0, 2))->toBe([16, 20])
        ->and(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeTrue();

    sampulRefresh(['--berkas' => ['sampul-nsif-*']]);

    expect(Storage::disk('local')->exists(CoverThumbnail::path($file)))->toBeFalse();

    // New bytes at the same path: another validator, so the browser's copy of the placeholder is not confirmed.
    $poster = $this->actingAs($kesiswaan)->get($url, ['If-None-Match' => $etag])->assertOk();

    expect(array_slice((array) getimagesizefromstring((string) $poster->getContent()), 0, 2))->toBe([512, 640])
        ->and((string) $poster->headers->get('ETag'))->not->toBe($etag);
});

it('adds the cover an older demo database lacks, only with --lengkapi', function (): void {
    sampulSeedDemo();

    // A database seeded before the covers existed: two lomba have none.
    $names = ['National Science and Invention Fair (NSIF) 2026', 'Lomba Debat Bahasa Indonesia (LDBI) Kota Semarang 2026'];

    foreach (Competition::query()->whereIn('name', $names)->with('coverFile')->get() as $competition) {
        Storage::disk('local')->delete($competition->coverFile->path);
        DB::table('competitions')->where('id', $competition->getKey())->update(['cover_file_id' => null]);
        $competition->coverFile->delete();
    }

    // A lomba somebody typed in with the same name (no juknis of the demo data) is not a demo lomba: left alone.
    $handmade = Competition::factory()->published()->create(['name' => $names[0]]);
    $rows = DB::table('activity_log')->count();
    $updated = DB::table('competitions')->max('updated_at');

    // Without --lengkapi nothing is added, but the command says what --lengkapi would do.
    expect(sampulRefresh(['--berkas' => ['tidak-ada-*']]))->toContain('Jalankan dengan --lengkapi untuk melengkapi: 2 lomba demo tanpa sampul.')
        ->and(Competition::query()->whereNotNull('cover_file_id')->count())->toBe(5);

    $this->travel(5)->minutes();

    expect(sampulRefresh(['--berkas' => ['sampul-*'], '--lengkapi' => true]))
        ->toContain('Sampul ditambahkan pada 2 lomba demo.', 'dibuat ulang · Sampul lomba: 7');

    $added = Competition::query()->whereIn('name', $names)->whereNotNull('cover_file_id')->with('coverFile')->get();

    expect($added)->toHaveCount(2)
        ->and($handmade->fresh()->cover_file_id)->toBeNull()
        ->and(Competition::query()->whereNotNull('cover_file_id')->count())->toBe(7)
        // Written without model events: no audit entry, and the lomba rows keep their timestamps.
        ->and(DB::table('activity_log')->count())->toBe($rows)
        ->and(DB::table('competitions')->max('updated_at'))->toBe($updated);

    foreach ($added as $competition) {
        $file = $competition->coverFile;
        [$width, $height] = getimagesizefromstring((string) Storage::disk('local')->get($file->path));

        expect($file->path)->toStartWith('demo/')
            ->and($file->purpose)->toBe(FilePurpose::Cover)
            ->and($file->original_name)->toBe(DemoCoverArt::fileNameFor($competition->name))
            ->and($file->owner_user_id)->toBe($competition->submitted_by)
            ->and([$width, $height])->toBe([DemoCoverArt::WIDTH, DemoCoverArt::HEIGHT]);
    }

    // Asking again adds nothing more.
    expect(sampulRefresh(['--berkas' => ['sampul-*'], '--lengkapi' => true]))->toContain('Sampul ditambahkan pada 0 lomba demo.', 'sudah terbaru (tidak diubah): 7')
        ->and(File::query()->where('purpose', FilePurpose::Cover)->count())->toBe(7);
});

it('writes on a poster what the lomba row says, and makes do with the file name for a cover it does not know', function (): void {
    sampulSeedDemo();

    $osn = Competition::query()->where('name', 'OSN Tingkat Kota Semarang 2026')->firstOrFail();
    $nsif = Competition::query()->where('name', 'National Science and Invention Fair (NSIF) 2026')->firstOrFail();

    expect(DemoCoverArt::describe('sampul-osn-kota-2026.jpg', $osn))->toBe([
        'look' => 'orbit',
        'short' => 'OSN',
        'title' => 'Olimpiade Sains Nasional',
        'year' => '2026',
        'date' => '14 Oktober 2026',
        'place' => 'Kota Semarang',
        'deadline' => 'Batas daftar 8 Oktober 2026',
    ])
        ->and(DemoCoverArt::describe('sampul-nsif-2026.jpg', $nsif))->toMatchArray(['look' => 'sirkuit', 'short' => 'NSIF', 'date' => '7 November 2026', 'place' => 'Daring'])
        // Attached to no lomba: the known texts, no dates.
        ->and(DemoCoverArt::describe('sampul-nsif-2026.jpg'))->toMatchArray(['short' => 'NSIF', 'year' => '2026', 'date' => '', 'place' => '', 'deadline' => ''])
        ->and(DemoCoverArt::describe('sampul-lomba-cerdas-cermat-2027.jpg'))->toMatchArray(['short' => 'CERDAS CERMAT', 'title' => 'Lomba pelajar', 'year' => '2027'])
        ->and(DemoCoverArt::fileNameFor('KRENOVA Kota Semarang 2027'))->toBeNull()
        ->and(DemoCoverArt::fileNameFor($nsif->name))->toBe('sampul-nsif-2026.jpg');

    // Every poster of the list has a look of its own.
    expect(array_unique(array_column(DemoCoverArt::POSTERS, 3)))->toHaveCount(count(DemoCoverArt::POSTERS));

    // The identifier follows the data, and a cover the list does not know is still drawn.
    $spec = DemoCoverArt::describe('sampul-osn-kota-2026.jpg', $osn);
    $unknown = DemoCoverArt::render(DemoCoverArt::describe('sampul-lomba-cerdas-cermat-2027.jpg'));

    expect(DemoCoverArt::id($spec))->toBe(DemoCoverArt::id(array_reverse($spec, true)))
        ->and(DemoCoverArt::id($spec))->not->toBe(DemoCoverArt::id(['date' => '15 Oktober 2026'] + $spec))
        ->and(array_slice((array) getimagesizefromstring($unknown), 0, 2))->toBe([DemoCoverArt::WIDTH, DemoCoverArt::HEIGHT])
        ->and(DemoCoverArt::hasId($unknown, DemoCoverArt::id($spec)))->toBeFalse();
});

it('still draws every poster without FreeType, in the built-in font', function (): void {
    foreach (array_keys(DemoCoverArt::POSTERS) as $fileName) {
        $spec = ['date' => '17 s.d. 18 Oktober 2026', 'place' => 'Kota Semarang', 'deadline' => 'Batas daftar 8 Oktober 2026'] + DemoCoverArt::describe($fileName);
        $bytes = DemoCoverArt::render($spec, false);
        [$width, $height, $type] = getimagesizefromstring($bytes);

        expect([$width, $height, $type])->toBe([DemoCoverArt::WIDTH, DemoCoverArt::HEIGHT, IMAGETYPE_JPEG], $fileName)
            ->and(DemoCoverArt::hasId($bytes, DemoCoverArt::id($spec, false)))->toBeTrue()
            // Not the picture FreeType would give, so a later refresh with FreeType draws it again.
            ->and(DemoCoverArt::hasId($bytes, DemoCoverArt::id($spec, true)))->toBeFalse();
    }
});

it('never draws a sample poster in production and never touches covers outside APP_ENV=local', function (): void {
    $file = DemoFiles::cover('sampul-osn-kota-2026.jpg');
    $bytes = Storage::disk('local')->get($file->path);

    foreach (['production', 'staging', 'testing'] as $environment) {
        app()['env'] = $environment;

        expect(fn () => app(DemoFileRefresher::class)->attachMissingCovers())->toThrow(RuntimeException::class, 'APP_ENV=local')
            ->and(fn () => app(DemoFileRefresher::class)->refresh(names: ['sampul-*']))->toThrow(RuntimeException::class, 'APP_ENV=local')
            ->and(fn () => app(DemoFileRefresher::class)->missing())->toThrow(RuntimeException::class, 'APP_ENV=local');
    }

    app()['env'] = 'production';

    expect(fn () => DemoCoverArt::render(DemoCoverArt::describe('sampul-osn-kota-2026.jpg')))->toThrow(RuntimeException::class, 'production')
        ->and(fn () => DemoCoverArt::write(storage_path('framework/testing/sampul')))->toThrow(RuntimeException::class, 'APP_ENV=local')
        ->and(Storage::disk('local')->get($file->path))->toBe($bytes)
        ->and($file->fresh()->sha256)->toBe($file->sha256);
});
