<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\Permission;
use App\Models\Competition;
use App\Models\File;
use App\Models\User;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Rules\ExternalUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Usulan lomba by siswa or guru (UI-MAP #10, guru `guru.lomba.index`). Always lands in
 * menunggu_verifikasi (D-27) with submitted_by = actor; only name, organizer, official link, note
 * and brochure. The brochure goes through StoreUpload (private, never public before verification).
 * Limit: 5 per 24 h per user (SECURITY §3d/§3m-2). The link is validated, never fetched (§3m-1).
 * Kesiswaan's own "Tambah lomba" (full form) is W2-B's CreateCompetition.
 */
final class SubmitCompetition
{
    public const DAILY_LIMIT = 5;

    public function __construct(private readonly StoreUpload $storeUpload) {}

    /**
     * @param  array{name?: mixed, organizer_name?: mixed, website_url?: mixed, description?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ?UploadedFile $brochure = null): Competition
    {
        Gate::forUser($actor)->authorize('create', Competition::class);

        $clean = $this->validated($data);

        $this->assertBelowDailyLimit($actor);

        $file = null;

        if ($brochure !== null) {
            try {
                $file = $this->storeUpload->store($brochure, FilePurpose::Juknis, $actor);
            } catch (InvalidUpload $e) {
                throw $e->toValidationException('brosur');
            }
        }

        try {
            $competition = DB::transaction(function () use ($actor, $clean, $file): Competition {
                // Serialise concurrent submissions of one user, then re-check the limit.
                User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
                $this->assertBelowDailyLimit($actor);

                $competition = new Competition([
                    'name' => $clean['name'],
                    'organizer_name' => $clean['organizer_name'],
                    'website_url' => $clean['website_url'],
                    'description' => $clean['description'],
                    'juknis_file_id' => $file?->getKey(),
                ]);
                $competition->forceFill([
                    'status' => CompetitionStatus::MenungguVerifikasi,
                    'submitted_by' => $actor->getKey(),
                ])->save();

                activity('competition')
                    ->performedOn($competition)
                    ->causedBy($actor)
                    ->event('competition.submitted')
                    ->withProperties(['has_brochure' => $file !== null])
                    ->log('competition.submitted');

                return $competition;
            });
        } catch (Throwable $e) {
            if ($file instanceof File) {
                $this->storeUpload->discard($file);
            }

            throw $e;
        }

        $verifiers = User::query()
            ->canLogIn()
            ->permission(Permission::CompetitionVerify->value)
            ->whereKeyNot($actor->getKey())
            ->get();

        Notification::send($verifiers, new CompetitionSubmitted($competition, (string) $actor->name));

        return $competition;
    }

    private function assertBelowDailyLimit(User $actor): void
    {
        $recent = Competition::withTrashed()
            ->where('submitted_by', $actor->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['name' => 'Kamu sudah mengirim '.self::DAILY_LIMIT.' usulan hari ini. Coba lagi besok.']);
        }
    }

    /**
     * Defence in depth: the screen validates too, but the Action never trusts its caller.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, organizer_name: ?string, website_url: ?string, description: ?string}
     */
    private function validated(array $data): array
    {
        $trim = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $clean = [
            'name' => $trim($data['name'] ?? null),
            'organizer_name' => $trim($data['organizer_name'] ?? null),
            'website_url' => $trim($data['website_url'] ?? null),
            'description' => $trim($data['description'] ?? null),
        ];

        $errors = [];

        if ($clean['name'] === null || mb_strlen($clean['name']) < 3 || mb_strlen($clean['name']) > 200) {
            $errors['name'] = 'Isi nama lomba (3–200 karakter).';
        }

        if ($clean['organizer_name'] !== null && mb_strlen($clean['organizer_name']) > 200) {
            $errors['organizer_name'] = 'Nama penyelenggara maks. 200 karakter.';
        }

        if ($clean['website_url'] !== null && ! ExternalUrl::passes($clean['website_url'])) {
            $errors['website_url'] = ExternalUrl::MESSAGE;
        }

        if ($clean['description'] !== null && mb_strlen($clean['description']) > 1000) {
            $errors['description'] = 'Catatan maks. 1000 karakter.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array{name: string, organizer_name: ?string, website_url: ?string, description: ?string} $clean */
        return $clean;
    }
}
