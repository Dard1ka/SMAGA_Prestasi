<?php

declare(strict_types=1);

use App\Domain\Files\PdfScanner;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\ReportFileKind;
use App\Enums\SettingKey;
use App\Models\File;
use App\Models\Letter;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\Demo\DemoDocumentKind;
use Database\Seeders\Support\Demo\DemoDocumentResolver;
use Database\Seeders\Support\Demo\DemoFileRefresher;
use Database\Seeders\Support\Demo\DemoPdfRenderer;
use Database\Seeders\Support\DemoCoverArt;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| M3-D · the demo files of the local demo data and `php artisan sth:demo:refresh-files`.
| - The demo seeder keeps the tiny placeholders in the test suite (rendering a hundred PDFs takes most of a minute);
|   on a real local seed DemoFileRefresher replaces them right after seeding.
| - Every demo file resolves to the right document from what it is attached to (no rendering needed to check).
| - The command only touches `files` rows under demo/: same path, new bytes, size and sha256; nothing else on the
|   disk and no other table; a second run changes nothing; it refuses to run outside APP_ENV=local.
| The slow full run (every file rendered and scanned, with timings) only runs with STH_DEMO_FULL=1.
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    DemoFiles::forceRealistic(null);
    app()['env'] = 'testing';
});

/** Seed the local demo data the way the other demo tests do (the seeder refuses any other environment). */
function m3dSeedDemo(): void
{
    app()['env'] = 'local';
    test()->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';
}

/** Run something as on a developer's machine. */
function m3dAsLocal(Closure $callback): mixed
{
    app()['env'] = 'local';

    try {
        return $callback();
    } finally {
        app()['env'] = 'testing';
    }
}

function m3dDemoFile(string $originalName): File
{
    return File::query()->where('original_name', $originalName)->where('path', 'like', 'demo/%')->orderBy('id')->firstOrFail();
}

/** Row counts and the newest change of the tables the command must leave alone. */
function m3dOtherTables(): array
{
    $state = [];

    foreach (['report_files', 'reports', 'registrations', 'registration_members', 'competitions', 'letters', 'talent_applications', 'achievements', 'settings', 'users', 'students', 'activity_log'] as $table) {
        $state[$table] = [DB::table($table)->count(), DB::getSchemaBuilder()->hasColumn($table, 'updated_at') ? DB::table($table)->max('updated_at') : null];
    }

    return $state;
}

/** The page content of a dompdf PDF: every stream, inflated (dompdf writes "stream\n…\nendstream"). */
function m3dPageText(string $bytes): string
{
    // No optional "\r" here: it would eat a compressed stream's last byte when that byte happens to be 0x0D.
    preg_match_all('~stream\n(.*?)\nendstream~s', $bytes, $matches);

    return implode("\n", array_map(fn (string $stream): string => (string) @gzuncompress($stream), $matches[1]));
}

it('keeps the tiny placeholder files when the demo data is seeded in the test suite', function (): void {
    m3dSeedDemo();

    $files = File::query()->where('path', 'like', 'demo/%')->get();

    expect(DemoFiles::realistic())->toBeFalse()
        ->and(Env::get('APP_ENV'))->toBe('testing')
        ->and($files)->toHaveCount(107) // 100 PDFs + the covers of 7 lomba (DemoCoversTest)
        ->and($files->where('mime', 'application/pdf'))->toHaveCount(100)
        ->and($files->max('size'))->toBeLessThan(2048)
        ->and($files->every(fn (File $f): bool => Storage::disk('local')->size($f->path) === $f->size))->toBeTrue();

    // New in M3: the riset report of the demo data carries its research report next to the certificate.
    $research = ReportFile::query()->where('kind', ReportFileKind::LaporanRiset)->with('file', 'report')->get();

    expect($research)->toHaveCount(1)
        ->and($research[0]->file->original_name)->toBe('laporan-penelitian-bioplastik-dari-limbah-kulit-pisang.pdf')
        ->and($research[0]->file->purpose)->toBe(FilePurpose::ReportEvidence)
        ->and($research[0]->report->research_title)->toBe('Bioplastik dari limbah kulit pisang untuk kemasan jajanan sekolah');
});

it('renders realistic files on a real local seed, never outside APP_ENV=local', function (): void {
    // phpunit.xml forces APP_ENV=testing for this process, so the rule itself says no; a test may override it.
    expect(m3dAsLocal(fn (): bool => DemoFiles::realistic()))->toBeFalse();

    DemoFiles::forceRealistic(true);

    expect(m3dAsLocal(fn (): bool => DemoFiles::realistic()))->toBeTrue()
        ->and(DemoFiles::realistic())->toBeFalse(); // not local: never

    foreach (['production', 'staging', 'testing'] as $environment) {
        app()['env'] = $environment;

        expect(fn () => app(DemoFileRefresher::class)->refresh())->toThrow(RuntimeException::class, 'APP_ENV=local')
            ->and(fn () => app(DemoFileRefresher::class)->attachMissing())->toThrow(RuntimeException::class, 'APP_ENV=local');
    }
});

it('works out the right document for every demo file from what it is attached to', function (): void {
    m3dSeedDemo();

    $resolver = app(DemoDocumentResolver::class);
    $renderer = app(DemoPdfRenderer::class);
    $kinds = [];
    $ids = [];

    // The PDFs: a cover is a drawn poster (DemoCoverArt), not a document of the resolver.
    foreach (File::query()->where('path', 'like', 'demo/%')->where('mime', 'application/pdf')->orderBy('id')->get() as $file) {
        [$document, $note] = $resolver->resolve($file);

        expect($note)->toBeNull("{$file->original_name}: {$note}");

        $kinds[$document->kind->value] = ($kinds[$document->kind->value] ?? 0) + 1;
        $ids[$file->original_name][$file->purpose->value] = $renderer->id($document);
    }

    ksort($kinds);

    expect($kinds)->toBe([
        'brosur' => 1,
        'juknis' => 8,
        'laporan_penelitian' => 1,
        'piagam' => 19, // sports reports + the two files named piagam-…
        'poster' => 1,
        'sertifikat' => 60,
        'surat_guru' => 5,
        'surat_siswa' => 5,
    ]);

    // A certificate a student uses twice (report evidence and talent evidence) is one document: one render, same bytes.
    foreach (['sertifikat-opsi-2026.pdf', 'sertifikat-okn-undip-2026.pdf', 'sertifikat-festival-tari-2026.pdf'] as $name) {
        expect($ids[$name]['talent_evidence'])->toBe($ids[$name]['report_evidence']);
    }

    // Spot checks of what the documents say.
    $opsi = $resolver->resolve(m3dDemoFile('sertifikat-opsi-2026.pdf'))[0];
    $o2sn = $resolver->resolve(m3dDemoFile('piagam-o2sn-prov-2026.pdf'))[0]; // Fajar's talent evidence, found by its name
    $smp = $resolver->resolve(m3dDemoFile('sertifikat-osn-smp-biologi-2025.pdf'))[0];
    $report = $resolver->resolve(m3dDemoFile('laporan-penelitian-bioplastik-dari-limbah-kulit-pisang.pdf'))[0];
    $team = $resolver->resolve(File::query()->where('original_name', 'sertifikat-nsif-2025.pdf')->firstOrFail())[0];
    $juknis = $resolver->resolve(m3dDemoFile('juknis-lpb-2026.pdf'))[0];
    $poster = $resolver->resolve(m3dDemoFile('poster-film-pendek.pdf'))[0];

    expect($opsi->data['name'])->toBe('Nadia Putri Anindya')
        ->and($opsi->data['result'])->toBe('JUARA II')
        ->and($opsi->data['theme'])->toBe('inovasi')
        ->and($opsi->data['body'])->toContain('OPSI 2026', 'tingkat nasional', 'Puspresnas', '4 s.d. 7 Agustus 2026', 'di Jakarta')
        ->and($o2sn->kind)->toBe(DemoDocumentKind::Piagam)
        ->and($o2sn->data['rows'][0])->toBe(['Nama', 'Fajar Ramadhan'])
        ->and($o2sn->data['rows'][2])->toBe(['Cabang olahraga', 'Pencak Silat'])
        ->and($o2sn->data['result'])->toBe('JUARA I')
        ->and($o2sn->data['body'])->toContain('O2SN Provinsi Jawa Tengah 2026')
        ->and($smp->data['name'])->toBe('Hana Safira')
        ->and($smp->data['affiliation'])->toBe('Peserta jenjang SMP, Kota Semarang')
        ->and($report->data['title'])->toBe('Bioplastik dari limbah kulit pisang untuk kemasan jajanan sekolah')
        ->and($report->data['authors'])->toBe([['name' => 'Keisha Larasati', 'nis' => '225226166', 'class' => 'XI-6']])
        ->and($report->data['mentor'])->toBe('Retno Wulandari, S.Pd.')
        ->and($report->data['competition'])->toBe('Lomba Karya Tulis Ilmiah Remaja Jawa Tengah 2026')
        ->and($team->data['name'])->toBe('Yoga Pratama')
        ->and($team->data['team'])->toBe('Bersama tim: Dimas Arya Nugroho dan Keisha Larasati')
        ->and($juknis->kind)->toBe(DemoDocumentKind::Juknis)
        ->and($juknis->data['sections'][3][1][0][1]['rows'][0][2])->toBe('1 September s.d. 10 Oktober 2026')
        ->and($juknis->data['sections'][3][1][0][1]['rows'][2][2])->toBe('17 Oktober 2026')
        ->and($poster->kind)->toBe(DemoDocumentKind::Poster)
        ->and($poster->data['name'])->toBe('Lomba Film Pendek Pelajar Semarang');

    // The letters use the school's real templates, with the letter's own number and verification code.
    $letter = Letter::query()->where('full_number', '400.3.8/312/MRD/IX/2026')->firstOrFail();
    $surat = $resolver->resolve(File::query()->findOrFail($letter->file_id))[0];

    expect($surat->kind)->toBe(DemoDocumentKind::SuratSiswa)
        ->and($surat->view)->toBe('surat-siswa')
        ->and($surat->data['number'])->toBe('400.3.8/312/MRD/IX/2026')
        ->and($surat->data['verification']['code'])->toBe($letter->verification_code)
        ->and(array_column($surat->data['content']->students, 'name'))->toBe(['NADIA PUTRI ANINDYA', 'DIMAS ARYA NUGROHO', 'KEISHA LARASATI'])
        ->and($surat->data['content']->signer['name'])->toBe('Drs. Bambang Hartono, M.Pd.');
});

it('never prints a name from outside the demo set', function (): void {
    m3dSeedDemo();

    $resolver = app(DemoDocumentResolver::class);

    // Someone edits the demo records by hand: a student, the signer of the letters.
    $nadia = Student::query()->where('nis', '225226201')->with('user')->firstOrFail();
    $nadia->user->forceFill(['name' => 'Orang Sungguhan'])->save();
    Setting::put(SettingKey::SignerName, 'Pejabat Sungguhan, M.Pd.');

    [$certificate, $note] = $resolver->resolve(m3dDemoFile('sertifikat-krenova-2026.pdf'));

    expect($note)->toBe('anggota di luar data contoh')
        ->and($certificate->data['name'])->toBe('Nadia Putri Anindya') // the fallback student of the spec, not the edited name
        ->and(serialize($certificate->data))->not->toContain('Orang Sungguhan');

    // A letter with that student is not rendered from its data at all; another letter keeps the fictional signer.
    $wice = Letter::query()->where('full_number', '400.3.8/312/MRD/IX/2026')->firstOrFail();
    [$general, $letterNote] = $resolver->resolve(File::query()->findOrFail($wice->file_id));

    expect($general->kind)->toBe(DemoDocumentKind::Umum)
        ->and($letterNote)->toBe('surat tidak bisa dibuat dari datanya')
        ->and(serialize($general->data))->not->toContain('Orang Sungguhan', 'Pejabat Sungguhan');

    $nadia->user->forceFill(['name' => 'Nadia Putri Anindya'])->save();
    $surat = $resolver->resolve(File::query()->findOrFail($wice->file_id))[0];

    expect($surat->kind)->toBe(DemoDocumentKind::SuratSiswa)
        ->and($surat->data['content']->signer['name'])->toBe('Drs. Bambang Hartono, M.Pd.')
        ->and(serialize($surat->data))->not->toContain('Pejabat Sungguhan');
});

it('replaces only the demo files it is asked for, at the same path, and changes nothing on a second run', function (): void {
    m3dSeedDemo();

    $disk = Storage::disk('local');

    // A file uploaded by hand during QA (another folder), a demo row attached to nothing, and a non-PDF in demo/.
    $disk->put('lampiran/2026/10/qa-upload.pdf', DemoFiles::pdfBytes(['Upload tangan']));
    $manual = File::query()->create([
        'disk' => 'local', 'path' => 'lampiran/2026/10/qa-upload.pdf', 'original_name' => 'sertifikat-krenova-2026.pdf',
        'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'purpose' => FilePurpose::ReportEvidence, 'owner_user_id' => null,
    ]);
    $loose = DemoFiles::pdf('sertifikat-lomba-cerdas-cermat-2026.pdf', FilePurpose::ReportEvidence);
    $disk->put('demo/gambar.png', 'bukan pdf');
    $image = File::query()->create([
        'disk' => 'local', 'path' => 'demo/gambar.png', 'original_name' => 'gambar.png',
        'mime' => 'image/png', 'size' => 9, 'sha256' => hash('sha256', 'bukan pdf'), 'purpose' => FilePurpose::ReportEvidence, 'owner_user_id' => null,
    ]);

    $names = [
        'sertifikat-krenova-2026.pdf', 'piagam-o2sn-2026.pdf', 'laporan-penelitian-*', 'sertifikat-opsi-2026.pdf', 'juknis-osn-kota-2026.pdf',
        'brosur-*', 'poster-*', '*wice26.pdf', 'sertifikat-lomba-cerdas-cermat-2026.pdf', 'gambar.png',
    ];
    $before = File::query()->where('path', 'like', 'demo/%')->get()->keyBy('id');
    $paths = $disk->allFiles('demo');
    $tables = m3dOtherTables();
    $manualBytes = $disk->get($manual->path);

    $run = fn (): string => m3dAsLocal(function () use ($names): string {
        expect(Artisan::call('sth:demo:refresh-files', ['--berkas' => $names]))->toBe(0);

        return Artisan::output();
    });
    $output = $run();

    $after = File::query()->where('path', 'like', 'demo/%')->get()->keyBy('id');
    $changed = $after->filter(fn (File $f): bool => $f->sha256 !== $before[$f->getKey()]->sha256);

    // 1 KRENOVA + 1 piagam + 1 research report + 2 OPSI (report and talent) + 1 juknis + 1 brochure + 1 poster + 2 letters + 1 loose
    expect($changed)->toHaveCount(11)
        ->and($after)->toHaveCount($before->count())
        ->and($disk->allFiles('demo'))->toEqualCanonicalizing($paths) // same paths: no new file, no orphan
        ->and(m3dOtherTables())->toBe($tables)
        ->and($disk->get($manual->path))->toBe($manualBytes)
        ->and($manual->fresh()->only(['size', 'sha256', 'path']))->toBe(['size' => 1, 'sha256' => str_repeat('a', 64), 'path' => 'lampiran/2026/10/qa-upload.pdf'])
        ->and($disk->get('demo/gambar.png'))->toBe('bukan pdf')
        ->and($image->fresh()->size)->toBe(9);

    foreach ($changed as $file) {
        $bytes = (string) $disk->get($file->path);

        expect($file->path)->toBe($before[$file->getKey()]->path)
            ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue()
            ->and($file->size)->toBe(strlen($bytes))->toBeGreaterThan(2048)
            ->and($file->sha256)->toBe(hash('sha256', $bytes))
            ->and(PdfScanner::hasActiveContent($bytes))->toBeFalse()
            ->and(m3dPageText($bytes))->toContain('bukan dokumen resmi');
    }

    // Everything that was not asked for is still the placeholder.
    expect($after->reject(fn (File $f): bool => $changed->has($f->getKey()))->where('mime', 'application/pdf')->max('size'))->toBeLessThan(2048);

    // What the refreshed files are.
    $opsi = File::query()->where('original_name', 'sertifikat-opsi-2026.pdf')->where('path', 'like', 'demo/%')->get();
    $letter = Letter::query()->where('full_number', '400.3.8/312/MRD/IX/2026')->firstOrFail();
    $guru = Letter::query()->where('registration_id', $letter->registration_id)->where('type', 'guru')->firstOrFail();
    $siswaText = m3dPageText((string) $disk->get(File::query()->findOrFail($letter->file_id)->path));
    $guruText = m3dPageText((string) $disk->get(File::query()->findOrFail($guru->file_id)->path));
    $looseText = m3dPageText((string) $disk->get($loose->path));

    expect($opsi)->toHaveCount(2)
        ->and($opsi[0]->sha256)->toBe($opsi[1]->sha256)
        ->and(m3dPageText((string) $disk->get(m3dDemoFile('sertifikat-krenova-2026.pdf')->path)))->toContain('Nadia Putri Anindya', 'JUARA I', 'KRENOVA Kota Semarang 2026')
        ->and(m3dPageText((string) $disk->get(m3dDemoFile('piagam-o2sn-2026.pdf')->path)))->toContain('PIAGAM PENGHARGAAN', 'Nadia Putri Anindya', 'PESERTA')
        ->and($siswaText)->toContain('S U R A T', '400.3.8/312/MRD/IX/2026', 'NADIA PUTRI ANINDYA', 'DIMAS ARYA NUGROHO', 'KEISHA LARASATI', $letter->verification_code, 'bukan dokumen resmi')
        ->and($guruText)->toContain('SURAT PERINTAH TUGAS', 'Retno Wulandari, S.Pd.', $guru->verification_code)
        ->and(substr_count($guruText, 'bukan dokumen resmi'))->toBe(2) // letter + Lampiran page
        ->and($looseText)->toContain('SERTIFIKAT', 'Lomba Cerdas Cermat 2026', 'Nadia Putri Anindya'); // nothing attached: the fallback student

    expect($output)->toContain('Sertifikat: 4', 'Piagam penghargaan: 1', 'Laporan penelitian: 1', 'Juknis lomba: 1', 'Brosur lomba: 1', 'Poster lomba: 1')
        ->and($output)->toContain('Surat tugas siswa: 1', 'Surat perintah tugas guru: 1', 'dilewati · bukan PDF demo: 1', 'catatan · tidak terpasang pada laporan: 1');

    // Second run: every file is up to date, nothing is written.
    $stamps = File::query()->where('path', 'like', 'demo/%')->pluck('updated_at', 'id')->map->toIso8601String()->all();
    $mtimes = collect($disk->allFiles('demo'))->mapWithKeys(fn (string $path): array => [$path => hash('sha256', (string) $disk->get($path))])->all();
    $this->travel(5)->minutes();

    $again = $run();

    expect($again)->toContain('sudah terbaru (tidak diubah): 11')->not->toContain('dibuat ulang')
        ->and(File::query()->where('path', 'like', 'demo/%')->pluck('updated_at', 'id')->map->toIso8601String()->all())->toBe($stamps)
        ->and(collect($disk->allFiles('demo'))->mapWithKeys(fn (string $path): array => [$path => hash('sha256', (string) $disk->get($path))])->all())->toBe($mtimes)
        ->and(m3dOtherTables())->toBe($tables);
});

it('refuses to run outside APP_ENV=local', function (): void {
    $file = DemoFiles::pdf('sertifikat-contoh-2026.pdf', FilePurpose::ReportEvidence);
    $bytes = Storage::disk('local')->get($file->path);

    foreach (['testing', 'production'] as $environment) {
        app()['env'] = $environment;

        $this->artisan('sth:demo:refresh-files', ['--force' => true, '--lengkapi' => true])
            ->expectsOutputToContain('hanya untuk APP_ENV=local')
            ->assertExitCode(1);
    }

    expect(Storage::disk('local')->get($file->path))->toBe($bytes)
        ->and($file->fresh()->sha256)->toBe($file->sha256);
});

it('adds the research report an older demo database lacks, only with --lengkapi', function (): void {
    m3dSeedDemo();

    // A database seeded before M3: the riset report has no research report file.
    $old = ReportFile::query()->where('kind', ReportFileKind::LaporanRiset)->with('file')->firstOrFail();
    Storage::disk('local')->delete($old->file->path);
    $old->delete();
    $old->file->delete();

    // A riset report with a research title whose evidence was uploaded by hand is not a demo report: left alone.
    $handmade = Report::query()->whereNull('research_title')->whereHas('files')->with('files.file')->firstOrFail();
    $handmade->forceFill(['research_title' => 'Judul buatan tangan'])->save();
    $handmade->files->first()->file->forceFill(['path' => 'lampiran/2026/10/bukti.pdf'])->save();

    // run(): a pending command otherwise runs when it goes out of scope, after m3dAsLocal() has set the environment back.
    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['laporan-penelitian-*']])->assertExitCode(0)->run());

    expect(ReportFile::query()->where('kind', ReportFileKind::LaporanRiset)->count())->toBe(0);

    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['laporan-penelitian-*'], '--lengkapi' => true])
        ->expectsOutputToContain('Laporan penelitian ditambahkan pada 1 laporan riset demo.')
        ->assertExitCode(0)
        ->run());

    $added = ReportFile::query()->where('kind', ReportFileKind::LaporanRiset)->with('file', 'report')->get();

    expect($added)->toHaveCount(1)
        ->and($added[0]->report->research_title)->toBe('Bioplastik dari limbah kulit pisang untuk kemasan jajanan sekolah')
        ->and($added[0]->file->path)->toStartWith('demo/')
        ->and($added[0]->file->owner_user_id)->toBe($added[0]->report->submitted_by)
        ->and($added[0]->file->size)->toBeGreaterThan(10 * 1024)
        ->and(m3dPageText((string) Storage::disk('local')->get($added[0]->file->path)))->toContain('LEMBAR PENGESAHAN', 'Keisha Larasati', 'DAFTAR PUSTAKA');

    // Asking again adds nothing more.
    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['laporan-penelitian-*'], '--lengkapi' => true])
        ->expectsOutputToContain('Laporan penelitian ditambahkan pada 0 laporan riset demo.')
        ->assertExitCode(0)
        ->run());

    expect(ReportFile::query()->where('kind', ReportFileKind::LaporanRiset)->count())->toBe(1);
});

it('renders and scans every demo file of a full local seed (slow, STH_DEMO_FULL=1)', function (): void {
    $started = microtime(true);
    m3dSeedDemo();
    $placeholder = microtime(true) - $started;

    $started = microtime(true);
    $summary = m3dAsLocal(fn (): array => app(DemoFileRefresher::class)->refresh());
    $realistic = microtime(true) - $started;

    $started = microtime(true);
    $again = m3dAsLocal(fn (): array => app(DemoFileRefresher::class)->refresh());
    $second = microtime(true) - $started;

    fwrite(STDERR, sprintf(
        "\n[M3-D] DatabaseSeeder with placeholders: %.1f s · refresh of %d files: %.1f s · second refresh: %.1f s · %d kB in all\n%s\n",
        $placeholder, $summary['total'], $realistic, $second, (int) round($summary['bytes'] / 1024), implode("\n", DemoFileRefresher::lines($summary)),
    ));

    // 100 PDFs + the covers of 7 lomba.
    expect($summary['total'])->toBe(107)
        ->and(array_sum($summary['written']))->toBe(107)
        ->and($summary['written'][DemoCoverArt::LABEL])->toBe(7)
        ->and($summary['skipped'])->toBe([])
        ->and($summary['general'])->toBe([])
        ->and($again['unchanged'])->toBe(107)
        ->and($again['written'])->toBe([]);

    foreach (File::query()->where('path', 'like', 'demo/%')->get() as $file) {
        $bytes = (string) Storage::disk('local')->get($file->path);

        if ($file->purpose === FilePurpose::Cover) {
            [$width, $height, $type] = getimagesizefromstring($bytes);

            expect([$width, $height, $type])->toBe([DemoCoverArt::WIDTH, DemoCoverArt::HEIGHT, IMAGETYPE_JPEG], $file->original_name)
                ->and($file->size)->toBe(strlen($bytes))->toBeLessThan(300 * 1024)
                ->and(substr($bytes, 0, 256))->toContain('CONTOH', 'bukan poster resmi');

            continue;
        }

        expect(PdfScanner::hasActiveContent($bytes))->toBeFalse($file->original_name)
            // The letters carry the two emblems of the school's letterhead and the sample signature (about 155 kB).
            ->and($file->size)->toBe(strlen($bytes))->toBeLessThan(($file->purpose === FilePurpose::Letter ? 300 : 100) * 1024)
            ->and(m3dPageText($bytes))->toContain('bukan dokumen resmi');
    }
})->skip(fn (): bool => Env::get('STH_DEMO_FULL') !== '1', 'slow: set STH_DEMO_FULL=1 to render every demo file');

// QA5 (2026-10-07): a demo surat tugas names the invitation it answers ----------------------------------------

it('gives every demo guru letter the number and date of the invitation it answers, the student letter none (D-154)', function (): void {
    m3dSeedDemo();

    $letters = Letter::query()->whereHas('file', fn ($q) => $q->where('path', 'like', 'demo/%'))->get();
    $guru = $letters->where('type', LetterType::Guru);

    expect($guru)->not->toBeEmpty()
        ->and($guru->filter(fn (Letter $l): bool => blank($l->basis_number) || $l->basis_date === null))->toBeEmpty()
        // The invitation is older than the letter that answers it.
        ->and($guru->filter(fn (Letter $l): bool => $l->basis_date->gte($l->issued_on)))->toBeEmpty()
        // Since D-154 the student letter's Dasar is the template's regulation text.
        ->and($letters->where('type', LetterType::Siswa)->filter(fn (Letter $l): bool => filled($l->basis_number) || $l->basis_date !== null))->toBeEmpty();
});

it('fills the invitation of an older demo letter only with --lengkapi, and says when there is something to fill', function (): void {
    m3dSeedDemo();

    $older = Letter::query()->where('type', LetterType::Guru)->whereHas('file', fn ($q) => $q->where('path', 'like', 'demo/%'))->orderBy('id')->firstOrFail();
    $number = $older->basis_number;
    $older->forceFill(['basis_number' => null, 'basis_date' => null])->save();

    // Without --lengkapi nothing is added, but the command says what --lengkapi would do.
    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['tidak-ada-*']])
        ->expectsOutputToContain('Jalankan dengan --lengkapi untuk melengkapi: 1 surat demo tanpa nomor undangan.')
        ->assertExitCode(0)
        ->run());

    expect($older->fresh()->basis_number)->toBeNull();

    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['tidak-ada-*'], '--lengkapi' => true])
        ->expectsOutputToContain('Nomor dan tanggal surat undangan dilengkapi pada 1 surat demo.')
        ->assertExitCode(0)
        ->run());

    expect($older->fresh()->basis_number)->toBe($number)
        ->and($older->fresh()->basis_date)->not->toBeNull();

    // A letter made by Tata Usaha (not in demo/) is never touched.
    $real = Letter::query()->where('type', LetterType::Guru)->whereHas('file', fn ($q) => $q->where('path', 'like', 'demo/%'))->orderByDesc('id')->firstOrFail();
    $real->file->forceFill(['path' => 'surat/2026/buatan-tu.pdf'])->save();
    $real->forceFill(['basis_number' => null, 'basis_date' => null])->save();

    m3dAsLocal(fn () => test()->artisan('sth:demo:refresh-files', ['--berkas' => ['tidak-ada-*'], '--lengkapi' => true])->assertExitCode(0)->run());

    expect($real->fresh()->basis_number)->toBeNull();
});
