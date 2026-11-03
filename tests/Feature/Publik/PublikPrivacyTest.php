<?php

declare(strict_types=1);

use App\Domain\Leaderboard\RankingScope;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $this->f = new PublikFixtures;
    $this->nadia = $this->f->student('Nadia Putri Anindya', 'XI-D', attributes: ['nis' => '225226201', 'nisn' => '0098765432']);
    User::query()->whereKey($this->nadia->user_id)->update(['email' => 'nadia.anindya@sth.test']);

    // Fictional student with a DISABLED account: since D-162 (no consent any more) the only kind that stays anonymous.
    $this->bima = $this->f->student('Bima Satria Wicaksono', 'XI-E', disabled: true, attributes: ['nis' => '225226777', 'nisn' => '0091234567']);
    User::query()->whereKey($this->bima->user_id)->update(['email' => 'bima.wicaksono@sth.test']);

    $this->opsi = $this->f->lomba('OPSI 2026', Bidang::Riset, Level::Nasional, '2026-09-20');
    $this->robotik = $this->f->lomba('Kompetisi Robotik Nasional 2025', Bidang::Riset, Level::Nasional, '2025-11-02');

    $this->f->prestasi($this->nadia, $this->opsi, ResultType::Juara2);
    $this->f->prestasi($this->bima, $this->opsi, ResultType::Juara2);   // same team, full points each (D-08)
    $this->f->prestasi($this->bima, $this->robotik, ResultType::Juara1);

    $this->slug = $this->f->slug($this->nadia);
});

/** Every public URL of the data set. @return list<string> */
function publikUrls(object $test): array
{
    return [
        route('public.home'),
        route('public.prestasi'),
        route('public.prestasi', ['bidang' => 'riset', 'siswa' => $test->slug]),
        route('public.prestasi.siswa', $test->slug),
        route('public.lomba.pemenang', $test->opsi->id),
        route('public.lomba.pemenang', $test->robotik->id),
        route('public.peringkat'),
        route('public.peringkat', ['status' => 'semua']),
        route('public.peringkat', ['status' => 'alumni']),
        route('public.peringkat', ['mode' => 'kelas']),
    ];
}

it('never shows the name of a disabled account, but counts it in the totals', function (): void {
    foreach (publikUrls($this) as $url) {
        $this->get($url)->assertOk()->assertDontSee('Bima Satria Wicaksono')->assertDontSee('Bima Satria');
    }

    $this->get(route('public.lomba.pemenang', $this->robotik->id))->assertSee('Siswa kelas XI');

    $overview = app(PublicAchievements::class)->overview();
    expect($overview['total'])->toBe(3)
        ->and($overview['students'])->toBe(2)
        ->and($overview['nasional'])->toBe(3);

    // A disabled account has no ranking row, in Kelas mode neither.
    $kelas = collect(app(PublicAchievements::class)->ranking('kelas'))->keyBy('kelas');
    expect($kelas->has('XI-E'))->toBeFalse()
        ->and($kelas->has('XI-D'))->toBeTrue();
});

it('names every other student on every public page, whatever the old consent columns say (D-162)', function (): void {
    Student::query()->whereKey($this->nadia->id)->update(['publish_consent' => false, 'photo_consent' => false]);
    PublicAchievements::flush();

    $this->get(route('public.peringkat'))->assertOk()->assertSee('Nadia Putri Anindya')->assertDontSee('Siswa kelas XI');
    $this->get(route('public.lomba.pemenang', $this->opsi->id))->assertOk()->assertSee('Nadia Putri Anindya');
    $this->get(route('public.prestasi', ['bidang' => 'riset']))->assertOk()->assertSee('Nadia Putri Anindya');
    $this->get(route('public.prestasi.siswa', $this->slug))->assertOk()->assertSee('Nadia Putri Anindya')
        // Public pages never show a photo of a student: initials only, no file of the student is linked.
        ->assertDontSee('/berkas/', false);

    $row = collect(app(PublicAchievements::class)->ranking('siswa'))->firstWhere('slug', $this->slug);
    expect($row)->toMatchArray(['name' => 'Nadia Putri Anindya', 'named' => true, 'kelas' => 'XI-D']);
});

it('never shows NIS, NISN, email or internal ids in public HTML', function (): void {
    foreach (publikUrls($this) as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        foreach (['225226201', '0098765432', 'nadia.anindya@sth.test', '225226777', '0091234567', 'bima.wicaksono@sth.test', $this->nadia->id, $this->bima->id] as $secret) {
            expect(str_contains($html, (string) $secret))->toBeFalse("{$secret} leaked on {$url}");
        }
    }
});

it('keeps the Livewire snapshot free of data (filters only)', function (): void {
    $html = $this->get(route('public.prestasi', ['bidang' => 'riset', 'siswa' => $this->slug]))->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = json_decode(html_entity_decode($match[1] ?? ''), true);

    // `limit` is the "Tampilkan lebih banyak" row count (a number, no personal data).
    expect(array_keys($snapshot['data'] ?? []))->toEqualCanonicalizing(['bidang', 'tahun', 'tingkat', 'siswa', 'limit']);
});

it('returns 404 for an unknown slug and for a disabled account', function (): void {
    $this->get('/prestasi/siswa/'.str_repeat('a', 20))->assertNotFound();
    $this->get('/prestasi/siswa/NOT-A-SLUG')->assertNotFound();

    $this->bima->forceFill(['public_slug' => str_repeat('b', 20)])->saveQuietly();
    PublicAchievements::flush();

    $this->get('/prestasi/siswa/'.str_repeat('b', 20))->assertNotFound();
    $this->get(route('public.prestasi', ['siswa' => str_repeat('b', 20)]))->assertOk()->assertDontSee('Bima Satria Wicaksono');
});

it('returns 404 for a lomba without verified achievements', function (): void {
    $empty = $this->f->lomba('LDBI Kota Semarang 2026', Bidang::SeniBudaya, Level::Kota, '2026-08-01');

    $this->get(route('public.lomba.pemenang', $empty->id))->assertNotFound();
    $this->get('/prestasi/lomba/'.str_repeat('Z', 26))->assertNotFound();
    $this->get('/prestasi/lomba/bukan-ulid')->assertNotFound();
});

it('hides a student once the account is disabled and the cache is flushed', function (): void {
    $this->get(route('public.peringkat'))->assertSee('Nadia Putri Anindya');

    User::query()->whereKey($this->nadia->user_id)->update(['status' => UserStatus::Disabled->value]);
    PublicAchievements::flush();

    $this->get(route('public.peringkat'))->assertDontSee('Nadia Putri Anindya');
    $this->get(route('public.lomba.pemenang', $this->opsi->id))->assertDontSee('Nadia Putri Anindya')->assertSee('Siswa kelas XI');
    $this->get(route('public.prestasi.siswa', $this->slug))->assertNotFound();
});

it('shows alumni with the Alumni label and their rank including alumni', function (): void {
    $ardi = $this->f->student('Ardiansyah Nur Hakim', 'XII-C', archived: true, attributes: ['grade12_start_date' => '2024-07-01']);
    $this->f->prestasi($ardi, $this->f->lomba('OSN Nasional 2024 Kimia', Bidang::Sains, Level::Nasional, '2024-10-05'), ResultType::Juara1);
    $slug = $this->f->slug($ardi);

    // M1-C: the last class of an alumnus is not shown any more.
    $this->get(route('public.prestasi.siswa', $slug))
        ->assertOk()
        ->assertSee('Alumni · lulus 2025')
        ->assertSee('termasuk alumni')
        ->assertDontSee('XII-C')
        ->assertDontSee('Kelas terakhir');
});

it('names an alumnus in every ranking group whatever the old consent column says, never with the last class (D-162)', function (): void {
    $tiara = $this->f->student('Tiara Anjani Lestari', 'XII-H', archived: true, attributes: ['grade12_start_date' => '2024-07-01', 'nis' => '222223555', 'nisn' => '0077123456', 'publish_consent' => false]);
    $this->f->prestasi($tiara, $this->robotik, ResultType::Juara2);
    PublicAchievements::flush();

    foreach (['alumni', 'semua'] as $status) {
        $html = $this->get(route('public.peringkat', ['status' => $status]))
            ->assertOk()
            ->assertSee('Alumni')
            ->assertSee('Tiara Anjani Lestari')
            ->assertDontSee('XII-H')
            ->getContent();

        foreach (['222223555', '0077123456', (string) $tiara->id] as $secret) {
            expect(str_contains($html, $secret))->toBeFalse("{$secret} leaked with status={$status}");
        }
    }

    $row = collect(app(PublicAchievements::class)->ranking('siswa', scope: RankingScope::Alumni))->sole();

    expect($row)->toMatchArray(['name' => 'Tiara Anjani Lestari', 'named' => true, 'kelas' => null, 'kelas_label' => 'Alumni'])
        ->and($row['slug'])->toMatch(PublicAchievements::SLUG_PATTERN);
});

it('keeps disabled accounts out of names and rankings but inside the totals', function (): void {
    $gone = $this->f->student('Laras Wening Pangestu', 'XI-G');
    $this->f->prestasi($gone, $this->robotik, ResultType::Finalis);
    User::query()->whereKey($gone->user_id)->update(['status' => UserStatus::Disabled->value]);
    PublicAchievements::flush();

    $this->get(route('public.peringkat'))->assertOk()->assertDontSee('Laras Wening Pangestu');
    $this->get(route('public.lomba.pemenang', $this->robotik->id))->assertOk()->assertDontSee('Laras Wening Pangestu');
    expect(app(PublicAchievements::class)->overview()['total'])->toBe(4);
});

/** Certificate of $student's achievement in $lomba, with registration memberships for $members. */
function publikCertificate(Student $student, Competition $lomba, array $members): ReportFile
{
    $report = Report::query()->findOrFail(Achievement::query()->where('student_id', $student->id)->where('competition_id', $lomba->id)->value('report_id'));
    RegistrationMember::query()->where('registration_id', $report->registration_id)->delete(); // factory leader

    foreach ($members as $member) {
        RegistrationMember::factory()->confirmed()->create(['registration_id' => $report->registration_id, 'student_id' => $member->id]);
    }

    return ReportFile::factory()->create(['report_id' => $report->id]);
}

it('links a certificate through files.public only when Admin allowed it and shows the verified badge otherwise', function (): void {
    $lkti = $this->f->lomba('LKTI Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-20');
    $this->f->prestasi($this->nadia, $lkti, ResultType::Juara1);
    $file = publikCertificate($this->nadia, $lkti, [$this->nadia]);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Terverifikasi')
        ->assertDontSee('Lihat sertifikat');

    Setting::put(SettingKey::PublicCertificates, true);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Lihat sertifikat')
        ->assertSee(route('files.public', $file->file_id), false)
        ->assertDontSee(route('files.show', $file->file_id), false);
});

it('never links a team certificate when one member\'s account is disabled', function (): void {
    // OPSI 2026: Nadia + Bima (disabled) share one report, so the certificate shows both names.
    $file = publikCertificate($this->nadia, $this->opsi, [$this->nadia, $this->bima]);
    Setting::put(SettingKey::PublicCertificates, true);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Terverifikasi')
        ->assertDontSee('Lihat sertifikat')
        ->assertDontSee(route('files.public', $file->file_id), false);
});

it('links a team certificate of named members whatever the old consent columns say (D-162)', function (): void {
    $dimas = $this->f->student('Dimas Arya Nugroho', 'XI-D', attributes: ['publish_consent' => false, 'photo_consent' => false]);
    $lkti = $this->f->lomba('LKTI Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-20');
    $this->f->prestasi($this->nadia, $lkti, ResultType::Juara1);
    $this->f->prestasi($dimas, $lkti, ResultType::Juara1);
    $file = publikCertificate($this->nadia, $lkti, [$this->nadia, $dimas]);
    Setting::put(SettingKey::PublicCertificates, true);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Lihat sertifikat')
        ->assertSee(route('files.public', $file->file_id), false);
});

it('assigns a random 20-hex slug to every student with achievements, but never links a disabled one', function (): void {
    $bimaSlug = Student::query()->whereKey($this->bima->id)->value('public_slug');

    expect($this->slug)->toMatch('/^[a-f0-9]{20}$/')
        ->and($bimaSlug)->toMatch('/^[a-f0-9]{20}$/');

    $this->get(route('public.prestasi.siswa', $bimaSlug))->assertNotFound();
    $this->get(route('public.peringkat'))->assertDontSee($bimaSlug);
});
