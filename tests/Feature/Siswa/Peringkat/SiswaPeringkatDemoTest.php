<?php

declare(strict_types=1);

use App\Livewire\Siswa\Peringkat;
use App\Models\Student;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
| B4 · backlog #2 with the local demo data set (DESIGN-SPEC-v2 §5): Nadia is #14 among active students on
| Beranda and Portofolio (W3-A RankConsistencyTest), so siswa.peringkat must say #14 and mark her row.
*/

uses(RefreshDatabase::class);

it('shows demo Nadia as #14 like Beranda and Portofolio', function (): void {
    $this->withoutVite();

    app()['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';

    $nadia = Student::query()->where('nis', '225226201')->firstOrFail();

    Livewire::actingAs($nadia->user)->test(Peringkat::class)
        ->assertViewHas('summary', fn (array $s): bool => $s['rank'] === 14)
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 14 && $me['me'] === true)
        ->assertSee('#14')
        ->assertSee('Kamu');
});
