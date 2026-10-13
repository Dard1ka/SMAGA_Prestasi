<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TalentStatus;
use Database\Factories\TalentApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pilihan talenta of a student (a student may have several, D-23). No unique index since D-143: `ApplyTalent`
 * refuses a duplicate (jenis + cabang + normalised `other_text`) under the student lock.
 *
 * `other_text` = the student's own text ("Sebutkan", 3–60 characters) for the jenis "Lainnya" or a cabang "Lainnya"
 * (D-143); null for every other choice. Plain text: escaped on screens, formula-escaped in Excel, kept out of the
 * activity log like `review_message`.
 *
 * Two notes with different readers (lead, 2026-10-04):
 * - `note` = catatan untuk guru pembina: internal, read by the guru pembina and Kesiswaan, never by the student.
 * - `student_note` = catatan untuk siswa: belongs to the latest decision (lolos, tidak lolos, dipulihkan); it is
 *   sent with that notification and shown on Profil talenta. A new decision replaces it (null = no note).
 * - `student_note_draft` = what Kesiswaan typed for the student and kept with "Simpan" (M2-B). Staff only:
 *   never shown or sent to the student. The next decision sends it as `student_note` and clears it.
 *
 * "Tidak lolos" can be undone: `rejected_from` keeps the stage before the rejection, `review_requested_at` +
 * `review_message` are the student's open "Minta ditinjau ulang" (one per rejection).
 *
 * Not mass-assignable: status, mentor_id, assessment_on, note, student_note, student_note_draft,
 * rejected_from, review_requested_at, review_message, other_text (app/Actions/Talent only).
 */
class TalentApplication extends Model
{
    /** @use HasFactory<TalentApplicationFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'talent_type_id',
        'talent_branch_id',
        'evidence_file_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'terdaftar',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TalentStatus::class,
            'assessment_on' => 'immutable_date',
            'rejected_from' => TalentStatus::class,
            'review_requested_at' => 'immutable_datetime',
        ];
    }

    /**
     * Default stage a "tidak lolos" row goes back to when Kesiswaan restores it without choosing another one
     * (D-104: the restore dialog offers Terdaftar, Pemetaan, Seleksi and Peserta pembinaan): the stage it had before the
     * rejection, or Terdaftar when that is unknown (rows rejected before `rejected_from` existed).
     */
    public function restoreTarget(): TalentStatus
    {
        $from = $this->rejected_from;

        return $from instanceof TalentStatus && ! $from->isFinal() ? $from : TalentStatus::Terdaftar;
    }

    /** The student asked for a second look and Kesiswaan has not restored the row yet. */
    public function hasOpenReviewRequest(): bool
    {
        return $this->status === TalentStatus::TidakLolos && $this->review_requested_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('talent')
            ->logAll()
            // `review_message` and `other_text` are the student's own text: the sealed log keeps only that there was
            // one (the audit row of the Action, D-63), never the text (QA9, D-143).
            ->logExcept(['created_at', 'updated_at', 'review_message', 'other_text'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Name of the choice on every screen, list and export: "OSN · Kimia", "OLPAIN", "OSN · Lainnya (Robotika)",
     * "Lainnya (Kaligrafi)" (D-143).
     */
    public function title(): string
    {
        $this->loadMissing(['talentType', 'talentBranch']);
        $type = $this->talentType?->code?->label() ?? 'Talenta';

        if ($this->talentBranch !== null) {
            return $type.' · '.$this->branchLabel();
        }

        return $this->otherText() !== null ? $type.' ('.$this->otherText().')' : $type;
    }

    /**
     * The cabang part alone (the "Cabang" column): "Kimia", "Lainnya (Robotika)", the text of a jenis "Lainnya"
     * ("Kaligrafi"), or "Tanpa cabang" (OLPAIN).
     */
    public function branchLabel(): string
    {
        $this->loadMissing(['talentType', 'talentBranch']);
        $text = $this->otherText();

        if ($this->talentBranch !== null) {
            return $this->talentBranch->name.($text !== null ? ' ('.$text.')' : '');
        }

        return $text ?? 'Tanpa cabang';
    }

    private function otherText(): ?string
    {
        $text = $this->other_text;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TalentType, $this> */
    public function talentType(): BelongsTo
    {
        return $this->belongsTo(TalentType::class);
    }

    /** @return BelongsTo<TalentBranch, $this> */
    public function talentBranch(): BelongsTo
    {
        return $this->belongsTo(TalentBranch::class);
    }

    /** @return BelongsTo<File, $this> */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'evidence_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    /** @param Builder<TalentApplication> $query */
    public function scopeInStatus(Builder $query, TalentStatus ...$statuses): void
    {
        $query->whereIn('status', $statuses);
    }

    /** Rejected rows whose student asked for a second look ("Minta ditinjau ulang"). @param Builder<TalentApplication> $query */
    public function scopeReviewRequested(Builder $query): void
    {
        $query->where('status', TalentStatus::TidakLolos)->whereNotNull('review_requested_at');
    }
}
