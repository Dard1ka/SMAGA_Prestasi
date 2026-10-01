<?php

declare(strict_types=1);

use App\Actions\Publications\FlyerRenderer;
use App\Actions\Publications\GenerateFlyer;
use App\Enums\FilePurpose;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Models\File;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| GenerateFlyer: 1080×1350 PNG on the private local disk, files row purpose flyer (W2-C).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->humas = HumasFixtures::humas();
});

it('renders a valid PNG for every template and stores it privately', function (string $template): void {
    $publication = HumasFixtures::publication();

    $file = app(GenerateFlyer::class)->handle($this->humas, $publication, $template);

    expect($file)->toBeInstanceOf(File::class)
        ->and($file->disk)->toBe('local')
        ->and($file->purpose)->toBe(FilePurpose::Flyer)
        ->and($file->mime)->toBe('image/png')
        ->and($file->original_name)->toBe('flyer-nadia-putri-anindya.png')
        ->and((int) $file->owner_user_id)->toBe((int) $this->humas->getKey())
        ->and($file->path)->toStartWith('flyer/'.now()->format('Y/m').'/')
        ->and($file->path)->toEndWith('.png');

    expect(Storage::disk('local')->exists($file->path))->toBeTrue();
    $bytes = Storage::disk('local')->get($file->path);
    $info = getimagesizefromstring($bytes);

    expect($info)->not->toBeFalse()
        ->and($info[0])->toBe(FlyerRenderer::WIDTH)
        ->and($info[1])->toBe(FlyerRenderer::HEIGHT)
        ->and($info['mime'])->toBe('image/png')
        ->and($file->size)->toBe(strlen($bytes))
        ->and($file->sha256)->toBe(hash('sha256', $bytes))
        ->and($publication->fresh()->flyer_file_id)->toBe($file->getKey())
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $log = Activity::query()->where('event', 'publication.flyer_generated')->sole();
    expect($log->properties['template'])->toBe($template);
})->with(array_keys(FlyerRenderer::TEMPLATES));

it('replaces the previous flyer (row and stored file)', function (): void {
    $publication = HumasFixtures::publication();
    $first = app(GenerateFlyer::class)->handle($this->humas, $publication, 'biru');
    $second = app(GenerateFlyer::class)->handle($this->humas, $publication->fresh(), 'emas');

    expect(File::query()->whereKey($first->getKey())->exists())->toBeFalse()
        ->and($publication->fresh()->flyer_file_id)->toBe($second->getKey())
        ->and(File::query()->where('purpose', FilePurpose::Flyer)->count())->toBe(1);

    expect(Storage::disk('local')->exists($first->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($second->path))->toBeTrue();
});

it('works for a published publication and long names', function (): void {
    $publication = HumasFixtures::publication(
        publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()],
        studentName: 'Muhammad Alif Nurrahman Syahputra Wicaksono Pradana',
        competitionName: 'Festival Lomba Seni dan Sastra Siswa Nasional Provinsi Jawa Tengah 2026 · Baca puisi tingkat SMA',
    );

    $file = app(GenerateFlyer::class)->handle($this->humas, $publication);

    expect(getimagesizefromstring(Storage::disk('local')->get($file->path)))->not->toBeFalse();
});

it('rejects an unknown template', function (): void {
    $publication = HumasFixtures::publication();

    expect(fn () => app(GenerateFlyer::class)->handle($this->humas, $publication, 'neon'))
        ->toThrow(ValidationException::class);
    expect(File::query()->count())->toBe(0);
});

it('refuses without publish consent and for other roles', function (): void {
    $noConsent = HumasFixtures::publication(['publish_consent' => false]);
    expect(fn () => app(GenerateFlyer::class)->handle($this->humas, $noConsent))->toThrow(AuthorizationException::class);

    $publication = HumasFixtures::publication(studentName: 'Raka Pradipta');
    expect(fn () => app(GenerateFlyer::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $publication))
        ->toThrow(AuthorizationException::class);

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('lets Humas download the stored flyer through the file controller', function (): void {
    config(['fortify.sth.require_staff_2fa' => false]);
    $publication = HumasFixtures::publication();
    $file = app(GenerateFlyer::class)->handle($this->humas, $publication);

    $this->actingAs($this->humas)->get(route('files.show', $file))->assertOk();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Guru))->get(route('files.show', $file))->assertNotFound();
})->skip(fn (): bool => ! app('router')->has('files.show'), 'files.show (W2-A1) not registered');
