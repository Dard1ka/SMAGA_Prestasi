<?php

declare(strict_types=1);

use App\Domain\Leaderboard\RankingScope;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Siswa\Beranda;
use App\Livewire\Siswa\Portofolio;
use App\Models\Student;
use App\Support\Leaderboard\PersonalRank;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Publik\PublikFixtures;

/*
| W3-A, lead decision D-37: a student's personal rank ("peringkatmu") is computed among ACTIVE students only
| and is the same number on siswa Beranda, Portofolio and the public student page; the public leaderboard
| includes alumni (flagged) only when asked (status filter "Alumni" / "Semua", M1-C).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('shows demo Nadia as #14 everywhere and #15 only on the public ranking with alumni', function (): void {
    app()['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';

    $nadia = Student::query()->where('nis', '225226201')->firstOrFail();
    $slug = (new PublikFixtures)->slug($nadia);
    $public = app(PublicAchievements::class);

    expect(app(PersonalRank::class)->rank($nadia))->toBe(14)
        ->and(app(PersonalRank::class)->summary($nadia))->toMatchArray(['total' => '104.00', 'rank' => 14])
        ->and($public->studentDetail($slug)['rank_active'])->toBe(14)
        ->and($public->studentDetail($slug)['rank_all'])->toBe(15)
        ->and(collect($public->ranking('siswa'))->firstWhere('name', 'Nadia Putri Anindya')['rank'])->toBe(14)
        ->and(collect($public->ranking('siswa', scope: RankingScope::All))->firstWhere('name', 'Nadia Putri Anindya')['rank'])->toBe(15)
        ->and(collect($public->ranking('siswa', scope: RankingScope::All))->contains(fn (array $row): bool => $row['alumni']))->toBeTrue()
        ->and(collect($public->ranking('siswa'))->contains(fn (array $row): bool => $row['alumni']))->toBeFalse()
        // M1-C: the alumni group holds alumni only (ranked among themselves), never Nadia.
        ->and(collect($public->ranking('siswa', scope: RankingScope::Alumni))->every(fn (array $row): bool => $row['alumni']))->toBeTrue()
        ->and(collect($public->ranking('siswa', scope: RankingScope::Alumni))->first()['rank'])->toBe(1);

    Livewire::actingAs($nadia->user)->test(Beranda::class)->assertSee('#14')->assertDontSee('#15');
    Livewire::actingAs($nadia->user)->test(Portofolio::class)->assertSee('#14')->assertSee('dari siswa aktif');
    $this->get(route('public.prestasi.siswa', $slug))->assertOk()->assertSee('#14')->assertSee('siswa aktif');
});

it('ignores alumni and disabled accounts for the personal rank', function (): void {
    $f = new PublikFixtures;
    $lomba = $f->lomba('OSN Nasional 2025', Bidang::Sains, Level::Nasional, '2025-09-10');
    $alumni = $f->student('Ardiansyah Nur Hakim', 'XII-3', archived: true);
    $raka = $f->student('Raka Pradipta', 'XII-2');
    $nadia = $f->student('Nadia Putri Anindya', 'XI-4');
    $dimas = $f->student('Dimas Arya Nugroho', 'XI-4');

    $f->prestasi($alumni, $lomba, ResultType::Juara1);   // 80
    $f->prestasi($raka, $lomba, ResultType::Juara1);     // 80
    $f->prestasi($dimas, $lomba, ResultType::Juara1);    // 80, then disabled
    $f->prestasi($nadia, $lomba, ResultType::Juara2);    // 68
    $dimas->user->forceFill(['status' => 'disabled'])->save();

    $rank = app(PersonalRank::class);

    expect($rank->rank($raka))->toBe(1)
        ->and($rank->rank($nadia))->toBe(2)
        ->and($rank->rank($alumni))->toBeNull()
        ->and($rank->rank($dimas))->toBeNull()
        ->and($rank->summary($f->student('Hana Safira', 'X-8')))->toBe(['total' => '0.00', 'rank' => null, 'count' => 0]);
});
