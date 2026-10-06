<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Enums\TalentStatus;
use App\Models\File;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A student joins the talent scouting for one jenis + cabang (D-21…D-23, docs/TALENT-FORM.md) with an
 * optional bukti dukung (StoreUpload, purpose talent_evidence, 5 MB). Several choices are allowed, the
 * same jenis + cabang only once; OLPAIN has no cabang (NULL), which a unique index cannot guard, so the
 * duplicate check runs here under a lock on the student row. A choice cancelled earlier (soft deleted
 * while terdaftar) is restored instead of inserted, keeping its old bukti unless a new one is given.
 * A choice that is "tidak lolos" cannot be applied for again: the student uses "Minta ditinjau ulang"
 * (RequestTalentReview) and Kesiswaan restores the same row (lead, 2026-10-04).
 * Validation keys: jenis, cabang, bukti.
 */
final class ApplyTalent
{
    public const REJECTED_MESSAGE = 'Pilihan ini belum lolos. Pakai "Minta ditinjau ulang" di daftar talenta pilihanmu.';

    public function __construct(private readonly StoreUpload $store) {}

    /** @throws ValidationException */
    public function handle(User $actor, TalentType $type, ?TalentBranch $branch, ?UploadedFile $evidence = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('create', TalentApplication::class);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        if ($type->requiresBranch()) {
            if ($branch === null) {
                throw ValidationException::withMessages(['cabang' => 'Pilih satu cabang dulu.']);
            }

            if ($branch->talent_type_id !== $type->getKey() || ! $branch->active) {
                throw ValidationException::withMessages(['cabang' => 'Cabang tidak tersedia untuk jenis talenta ini.']);
            }
        } elseif ($branch !== null) {
            throw ValidationException::withMessages(['cabang' => 'Jenis talenta ini tidak punya cabang.']);
        }

        $file = null;

        if ($evidence !== null) {
            try {
                $file = $this->store->store($evidence, FilePurpose::TalentEvidence, $actor);
            } catch (InvalidUpload $e) {
                throw $e->toValidationException('bukti');
            }
        }

        try {
            return DB::transaction(fn (): TalentApplication => $this->persist($actor, $student, $type, $branch, $file));
        } catch (Throwable $e) {
            if ($file !== null) {
                $this->store->discard($file);
            }

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function persist(User $actor, Student $student, TalentType $type, ?TalentBranch $branch, ?File $file): TalentApplication
    {
        // Serialises every talent change of this student (the OLPAIN NULL-branch duplicate check needs it).
        Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

        /** @var TalentApplication|null $existing */
        $existing = TalentApplication::withTrashed()
            ->where('student_id', $student->getKey())
            ->where('talent_type_id', $type->getKey())
            ->when(
                $branch === null,
                fn ($q) => $q->whereNull('talent_branch_id'),
                fn ($q) => $q->where('talent_branch_id', $branch?->getKey()),
            )
            ->lockForUpdate()
            ->first();

        if ($existing !== null && ! $existing->trashed()) {
            // A rejected choice is not applied for a second time (no second row, no silent reset): the student
            // asks for a second look instead and Kesiswaan restores the same row (RestoreTalentApplication).
            throw ValidationException::withMessages(['cabang' => $existing->status === TalentStatus::TidakLolos
                ? self::REJECTED_MESSAGE
                : 'Sudah dipilih. Pilih cabang lain.']);
        }

        if ($existing !== null) {
            $existing->restore();
            $application = $existing;

            if ($file !== null) {
                $application->evidence_file_id = $file->getKey();
            }

            $application->forceFill([
                'status' => TalentStatus::Terdaftar,
                'mentor_id' => null,
                'assessment_on' => null,
                'note' => null,
                'student_note' => null,
                'student_note_draft' => null,
                'rejected_from' => null,
                'review_requested_at' => null,
                'review_message' => null,
            ])->save();
        } else {
            $application = new TalentApplication([
                'student_id' => $student->getKey(),
                'talent_type_id' => $type->getKey(),
                'talent_branch_id' => $branch?->getKey(),
                'evidence_file_id' => $file?->getKey(),
            ]);
            $application->forceFill(['status' => TalentStatus::Terdaftar])->save();
        }

        activity('talent')
            ->performedOn($application)
            ->causedBy($actor)
            ->event('talent.applied')
            ->withProperties([
                'talent_type' => $type->code->value,
                'talent_branch_id' => $branch?->getKey(),
                'restored' => $existing !== null,
                'evidence' => $application->evidence_file_id !== null,
            ])
            ->log('talent.applied');

        return $application->refresh();
    }
}
