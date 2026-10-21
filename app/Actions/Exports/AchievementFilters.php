<?php

declare(strict_types=1);

namespace App\Actions\Exports;

use App\Domain\Archive\SchoolYear;
use App\Enums\Bidang;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\RegistrationType;
use App\Enums\ResultType;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters of the Kesiswaan "Database prestasi" (backlog #4, owner B4), shared by the screen and the Excel
 * export so both always show the same rows. Built from raw URL/Livewire input: every unknown or invalid
 * value becomes null (= no filter), never an error. Read-only query logic; no business rules.
 *
 * - tahun ajaran = school year of the lomba's final date (fallback: verification date), as on public pages
 * - kelas = the student's current class · angkatan = year of grade12_start_date - 2 (fallback: class level)
 * - tingkat and capaian = the snapshot on the achievement; jenis penyelenggara / status ajang = the lomba
 * - pembimbing and individu/tim = the registration of the verified report
 */
final readonly class AchievementFilters
{
    public const KEYS = ['tahun', 'q', 'kelas', 'angkatan', 'bidang', 'tingkat', 'jenis', 'ajang', 'pembimbing', 'tipe', 'capaian'];

    public function __construct(
        public ?SchoolYear $tahun = null,
        public ?string $q = null,
        public ?string $kelas = null,
        public ?string $kelasName = null,
        public ?int $angkatan = null,
        public ?Bidang $bidang = null,
        public ?Level $tingkat = null,
        public ?OrganizerType $jenis = null,
        public ?EventStatus $ajang = null,
        public ?int $pembimbing = null,
        public ?string $pembimbingName = null,
        public ?RegistrationType $tipe = null,
        public ?ResultType $capaian = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  keys from self::KEYS (strings from the URL or Livewire)
     */
    public static function fromInput(array $input): self
    {
        $text = fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $tahun = SchoolYear::isValid($text('tahun')) ? SchoolYear::fromString($text('tahun')) : null;
        $q = mb_substr($text('q'), 0, 100);

        $kelas = null;
        if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $text('kelas')) === 1) {
            $kelas = Classroom::query()->whereKey($text('kelas'))->first(['id', 'name']);
        }

        $angkatan = ctype_digit($text('angkatan')) && (int) $text('angkatan') >= 2000 && (int) $text('angkatan') <= 2100
            ? (int) $text('angkatan')
            : null;

        $mentor = null;
        if (ctype_digit($text('pembimbing')) && strlen($text('pembimbing')) <= 18) {
            $mentor = User::withTrashed()->whereKey((int) $text('pembimbing'))->first(['id', 'name']);
        }

        return new self(
            tahun: $tahun,
            q: $q !== '' ? $q : null,
            kelas: $kelas !== null ? (string) $kelas->getKey() : null,
            kelasName: $kelas?->name,
            angkatan: $angkatan,
            bidang: Bidang::tryFrom($text('bidang')),
            tingkat: Level::tryFrom($text('tingkat')),
            jenis: OrganizerType::tryFrom($text('jenis')),
            ajang: EventStatus::tryFrom($text('ajang')),
            pembimbing: $mentor !== null ? (int) $mentor->getKey() : null,
            pembimbingName: $mentor?->name,
            tipe: RegistrationType::tryFrom($text('tipe')),
            capaian: ResultType::tryFrom($text('capaian')),
        );
    }

    /**
     * @param  Builder<Achievement>  $query
     * @return Builder<Achievement>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->tahun !== null) {
            $start = sprintf('%04d-07-01', $this->tahun->startYear);
            $end = sprintf('%04d-06-30', $this->tahun->endYear());

            $query->where(fn (Builder $w) => $w
                ->whereHas('competition', fn (Builder $c) => $c->whereBetween('final_on', [$start, $end]))
                ->orWhere(fn (Builder $v) => $v
                    ->whereHas('competition', fn (Builder $c) => $c->whereNull('final_on'))
                    ->whereBetween('achievements.verified_at', [$start.' 00:00:00', $end.' 23:59:59'])));
        }

        if ($this->q !== null) {
            $like = '%'.addcslashes($this->q, '%_\\').'%';
            $nisn = ctype_digit($this->q) ? $this->q : null;

            $query->whereHas('student', fn (Builder $s) => $s->where(fn (Builder $w) => $w
                ->whereHas('user', fn (Builder $u) => $u->where('name', 'like', $like))
                ->when($nisn !== null, fn (Builder $n) => $n->orWhere('nisn', $nisn))));
        }

        if ($this->kelas !== null) {
            $query->whereHas('student', fn (Builder $s) => $s->where('classroom_id', $this->kelas));
        }

        if ($this->angkatan !== null) {
            $grade12Year = $this->angkatan + 2;
            $level = 10 + SchoolYear::containing(now())->startYear - $this->angkatan;

            $query->whereHas('student', fn (Builder $s) => $s->where(fn (Builder $w) => $w
                ->whereBetween('grade12_start_date', [$grade12Year.'-01-01', $grade12Year.'-12-31'])
                ->orWhere(fn (Builder $n) => $n->whereNull('grade12_start_date')
                    ->whereHas('classroom', fn (Builder $c) => $c->where('level', $level)))));
        }

        if ($this->bidang !== null) {
            $query->whereHas('competition', fn (Builder $c) => $c->where('bidang', $this->bidang));
        }

        if ($this->jenis !== null) {
            $query->whereHas('competition', fn (Builder $c) => $c->where('organizer_type', $this->jenis));
        }

        if ($this->ajang !== null) {
            $query->whereHas('competition', fn (Builder $c) => $c->where('event_status', $this->ajang));
        }

        if ($this->tingkat !== null) {
            $query->where('achievements.level', $this->tingkat);
        }

        if ($this->capaian !== null) {
            $query->where('achievements.result', $this->capaian);
        }

        if ($this->pembimbing !== null || $this->tipe !== null) {
            $query->whereHas('report', fn (Builder $r) => $r->withTrashed()->whereHas('registration', fn (Builder $g) => $g
                ->withTrashed()
                ->when($this->pembimbing !== null, fn (Builder $m) => $m->where('mentor_id', $this->pembimbing))
                ->when($this->tipe !== null, fn (Builder $t) => $t->where('type', $this->tipe))));
        }

        return $query;
    }

    /** @return array<string, string> active filters as raw values (URL / audit log), never names or NISN lists */
    public function toArray(): array
    {
        return array_filter([
            'tahun' => $this->tahun?->toString(),
            'q' => $this->q,
            'kelas' => $this->kelas,
            'angkatan' => $this->angkatan !== null ? (string) $this->angkatan : null,
            'bidang' => $this->bidang?->value,
            'tingkat' => $this->tingkat?->value,
            'jenis' => $this->jenis?->value,
            'ajang' => $this->ajang?->value,
            'pembimbing' => $this->pembimbing !== null ? (string) $this->pembimbing : null,
            'tipe' => $this->tipe?->value,
            'capaian' => $this->capaian?->value,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /** @return list<string> "Bidang: Sains" lines for the export header and the screen summary */
    public function describe(): array
    {
        return array_values(array_filter([
            $this->tahun !== null ? 'Tahun ajaran: '.$this->tahun->toString() : null,
            $this->q !== null ? 'Siswa: '.$this->q : null,
            $this->kelasName !== null ? 'Kelas: '.$this->kelasName : null,
            $this->angkatan !== null ? 'Angkatan: '.$this->angkatan : null,
            $this->bidang !== null ? 'Bidang: '.$this->bidang->label() : null,
            $this->tingkat !== null ? 'Tingkat: '.$this->tingkat->label() : null,
            $this->jenis !== null ? 'Jenis penyelenggara: '.$this->jenis->label() : null,
            $this->ajang !== null ? 'Status ajang: '.$this->ajang->label() : null,
            $this->pembimbingName !== null ? 'Pembimbing: '.$this->pembimbingName : null,
            $this->tipe !== null ? 'Peserta: '.$this->tipe->label() : null,
            $this->capaian !== null ? 'Capaian: '.$this->capaian->label() : null,
        ]));
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
