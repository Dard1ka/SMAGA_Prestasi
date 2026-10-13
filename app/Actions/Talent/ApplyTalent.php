<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
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
 * A student joins the talent database with one jenis + cabang (D-21…D-23, docs/TALENT-FORM.md) and an
 * optional bukti dukung (StoreUpload, purpose talent_evidence, 5 MB). Several choices are allowed, the
 * same one only once. D-143: the jenis "Lainnya" (no cabang) and every cabang "Lainnya" need the student's own
 * text ("Sebutkan", `TalentOtherText`, 3–60 characters); such a choice is jenis + cabang + that text, compared
 * without case and extra spaces, so one student may name several "Lainnya" talents. No unique index guards a
 * choice (nor the NULL cabang of OLPAIN), so the duplicate check runs here under a lock on the student row. A
 * choice deleted earlier is restored instead of inserted, keeping its old bukti unless a new one is given, and its
 * guru pembina and note for him.
 * D-142: there are no stages any more; every new or restored row is `terdaftar`, and an old row of any status
 * (also "tidak lolos") is simply a choice the student already has.
 * Validation keys: jenis, cabang, lainnya, bukti.
 */
final class ApplyTalent
{
    /** A choice of an ordinary cabang (or OLPAIN) the student already has; key `cabang`. */
    public const DUPLICATE_MESSAGE = 'Sudah dipilih. Pilih cabang lain.';

    /** A "Lainnya" text the student already chose for this jenis + cabang; key `lainnya`. */
    public const DUPLICATE_OTHER_MESSAGE = 'Talenta ini sudah kamu pilih.';

    public function __construct(private readonly StoreUpload $store) {}

    /** Whether this choice needs the student's own text: the jenis "Lainnya", or a cabang named "Lainnya" (D-143). */
    public static function needsOtherText(TalentType $type, ?TalentBranch $branch): bool
    {
        return $type->code === TalentTypeCode::Lainnya || ($branch !== null && $branch->isOther());
    }

    /**
     * @param  ?string  $otherText  the "Sebutkan" text; ignored unless needsOtherText()
     *
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        TalentType $type,
        ?TalentBranch $branch,
        ?UploadedFile $evidence = null,
        ?string $otherText = null,
    ): TalentApplication {
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

        // Checked before the upload is stored, so a refused text leaves no file behind.
        $other = self::needsOtherText($type, $branch) ? $this->otherText($otherText) : null;
        $file = null;

        if ($evidence !== null) {
            try {
                $file = $this->store->store($evidence, FilePurpose::TalentEvidence, $actor);
            } catch (InvalidUpload $e) {
                throw $e->toValidationException('bukti');
            }
        }

        try {
            return DB::transaction(fn (): TalentApplication => $this->persist($actor, $student, $type, $branch, $other, $file));
        } catch (Throwable $e) {
            if ($file !== null) {
                $this->store->discard($file);
            }

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function otherText(?string $value): string
    {
        $text = TalentOtherText::clean($value);

        if ($text === null) {
            throw ValidationException::withMessages(['lainnya' => TalentOtherText::REQUIRED_MESSAGE]);
        }

        $length = mb_strlen($text);

        if ($length < TalentOtherText::MIN || $length > TalentOtherText::MAX) {
            throw ValidationException::withMessages(['lainnya' => TalentOtherText::LENGTH_MESSAGE]);
        }

        return $text;
    }

    /** @throws ValidationException */
    private function persist(User $actor, Student $student, TalentType $type, ?TalentBranch $branch, ?string $other, ?File $file): TalentApplication
    {
        // Serialises every talent change of this student: no unique index guards a choice (NULL cabang, D-143 texts).
        Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

        $key = TalentOtherText::key($other);

        /** @var TalentApplication|null $existing the same choice; a live row wins over a deleted one */
        $existing = TalentApplication::withTrashed()
            ->where('student_id', $student->getKey())
            ->where('talent_type_id', $type->getKey())
            ->when(
                $branch === null,
                fn ($q) => $q->whereNull('talent_branch_id'),
                fn ($q) => $q->where('talent_branch_id', $branch?->getKey()),
            )
            ->lockForUpdate()
            ->get()
            ->filter(fn (TalentApplication $a): bool => TalentOtherText::key($a->other_text) === $key)
            ->sortBy(fn (TalentApplication $a): int => $a->trashed() ? 1 : 0)
            ->first();

        if ($existing !== null && ! $existing->trashed()) {
            // Whatever its (old) status: the student already has this choice (D-142).
            throw ValidationException::withMessages($other !== null
                ? ['lainnya' => self::DUPLICATE_OTHER_MESSAGE]
                : ['cabang' => self::DUPLICATE_MESSAGE]);
        }

        if ($existing !== null) {
            $existing->restore();
            $application = $existing;

            if ($file !== null) {
                $application->evidence_file_id = $file->getKey();
            }

            // A restore is an undo of "Hapus" (D-142): the guru pembina and the note for him stay as Kesiswaan left
            // them; the fields of the old stages are reset.
            $application->forceFill([
                'status' => TalentStatus::Terdaftar,
                'other_text' => $other,
                'assessment_on' => null,
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
            $application->forceFill(['status' => TalentStatus::Terdaftar, 'other_text' => $other])->save();
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
                // Only whether there is a text, never the text itself (D-63, D-143).
                'other_text' => $other !== null,
            ])
            ->log('talent.applied');

        return $application->refresh();
    }
}
