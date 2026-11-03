<?php

declare(strict_types=1);

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

it('searches students by name or exact NISN, never by NIS, and a NIS equal to another NISN finds only the NISN owner', function (): void {
    $nadia = H::pendingStudent();
    $nadia->user->forceFill(['name' => 'Nadia Putri Anindya'])->save();
    $other = Student::factory()->create(['nis' => '0091234569', 'nisn' => '0091234570']);

    expect(Student::query()->search(H::NISN)->pluck('id')->all())->toBe([$nadia->getKey()])
        ->and(Student::query()->search(H::NIS)->count())->toBe(0)
        ->and(Student::query()->search('0091234569')->count())->toBe(0)
        ->and(Student::query()->search('0091234570')->pluck('id')->all())->toBe([$other->getKey()])
        ->and(Student::query()->search('Putri Anindya')->pluck('id')->all())->toContain($nadia->getKey());
});
