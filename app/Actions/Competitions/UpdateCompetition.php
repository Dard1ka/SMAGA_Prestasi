<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan/Admin edits a lomba (queued or published): same fields as CreateCompetition. Changes to the
 * weight fields (tingkat, kategori, status ajang) get their own audit event (D-02); existing achievements
 * keep their snapshot. A published lomba must stay complete (VerifyCompetition::REQUIRED_TO_PUBLISH).
 * Status is never changed here (VerifyCompetition / CloseCompetition).
 */
final class UpdateCompetition
{
    private const WEIGHT_FIELDS = ['level', 'weight_category', 'event_status'];

    /**
     * @param  array<string, mixed>  $input  form fields (CreateCompetition::rules())
     */
    public function handle(
        User $actor,
        Competition $competition,
        array $input,
        ?UploadedFile $juknis = null,
        ?UploadedFile $cover = null,
        bool $removeCover = false,
    ): Competition {
        Gate::forUser($actor)->authorize('update', $competition);

        $data = CreateCompetition::normalize(
            Validator::make($input, CreateCompetition::rules(), CreateCompetition::messages())->validate(),
        );

        $juknisFile = $juknis !== null ? CreateCompetition::storeFile($actor, $juknis, FilePurpose::Juknis) : null;
        $coverFile = $cover !== null ? CreateCompetition::storeFile($actor, $cover, FilePurpose::Cover) : null;

        $result = DB::transaction(function () use ($actor, $competition, $data, $juknisFile, $coverFile, $removeCover): Competition {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CompetitionStatus::MenungguVerifikasi, CompetitionStatus::Dipublikasikan], true)) {
                throw ValidationException::withMessages(['status' => 'Lomba ini tidak bisa diubah lagi.']);
            }

            $before = array_map(
                fn (string $field): ?string => $locked->getAttribute($field)?->value,
                array_combine(self::WEIGHT_FIELDS, self::WEIGHT_FIELDS),
            );

            $locked->fill($data);

            if ($juknisFile !== null) {
                $locked->juknis_file_id = $juknisFile->getKey();
            }

            if ($coverFile !== null) {
                $locked->cover_file_id = $coverFile->getKey();
            } elseif ($removeCover) {
                $locked->cover_file_id = null;
            }

            if ($locked->status === CompetitionStatus::Dipublikasikan) {
                $missing = VerifyCompetition::missingFields($locked);

                if ($missing !== []) {
                    throw ValidationException::withMessages([
                        'status' => 'Lomba yang sudah tampil di katalog harus lengkap: '.implode(', ', $missing).'.',
                    ]);
                }
            }

            $locked->save();

            $after = array_map(
                fn (string $field): ?string => $locked->getAttribute($field)?->value,
                array_combine(self::WEIGHT_FIELDS, self::WEIGHT_FIELDS),
            );

            if ($before !== $after) {
                activity('competition')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('competition.weights_changed')
                    ->withProperties(['old' => $before, 'attributes' => $after, 'status' => $locked->status->value])
                    ->log('competition.weights_changed');
            }

            return $locked;
        });

        $competition->setRawAttributes($result->getAttributes(), true);

        return $result;
    }
}
