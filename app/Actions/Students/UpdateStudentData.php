<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Domain\Accounts\InitialPassword;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SV6, D-187 "Ubah data siswa": staff with `student.update` (Admin, Kesiswaan, Tata Usaha) correct the name, NIS, NISN,
 * birth date and class of ONE student who is not in the Sampah, e.g. after an import with a typing slip. Called by the
 * shared dialog App\Livewire\Akun\UbahDataSiswa.
 *
 * Rules (the import's, ImportStudents, with staff wording; only a field that changed is checked, an unchanged one is kept
 * as stored):
 * - Nama lengkap: required, at most NAME_MAX characters, letters (and . , ' ’ - and spaces), spaces squeezed.
 * - NIS 4–20 digits, NISN exactly 10 digits, each unique in its own column against EVERY student, the Sampah included
 *   (the unique index still holds those rows): a number of a student in the Sampah gets its own sentence. The NIS may
 *   equal another student's NISN (D-137). Two saves that meet on the unique index (UniqueConstraintViolationException)
 *   answer with the same field error.
 * - Tanggal lahir DD-MM-YYYY (the import's formats), age MIN_AGE–MAX_AGE on the day of the save (Asia/Jakarta).
 * - Kelas: an active class not in the Sampah. The same tingkat keeps the archive dates; another tingkat recomputes them
 *   like the import, GraduationCalculator with the school year of today (SchoolYear::containing, the calculator itself is
 *   unchanged), and refuses an archive date that is not in the future. An archived student (alumni) keeps the class.
 *
 * The initial password (D-173, CLAUDE.md rule 3): a new birth date re-hashes the password ONLY when it is still the
 * birth-date password of the old date, the conditions of ApplyBirthDateInitialPasswords: status pending_activation,
 * must_change_password, no temporary password of an Admin, and Hash::check(InitialPassword::forBirthDate(old date)).
 * Then password = Hash::make(forBirthDate(new date)); initial_password_expires_at, the sessions and everything else stay.
 * An active student, a temporary password, the configured value of D-138 and a password of the student's own are left
 * alone. The value is never shown, returned, logged or audited.
 *
 * One transaction: the users and students rows locked, compared with the snapshot the dialog showed (changed meanwhile
 * → KEY_STUDENT / MSG_CHANGED; gone or in the Sampah → MSG_GONE), only changed fields written (`forceFill`; the user row
 * with saveQuietly(), so the User model's own activity log never stores the name). Exactly ONE audit row per save:
 * `student.data_updated` (log `student`, also a security-log line, SecurityEventSubscriber) with the field names, the
 * screen (`source`) and booleans only: never a name, NIS, NISN, birth date, class or password. Nobody is notified.
 * PublicAchievements::flush() after the commit when the name or the class changed (public pages and ranking).
 */
final class UpdateStudentData
{
    public const EVENT = 'student.data_updated';

    /** The editable fields, in the order of the import template (the audit row names them so). */
    public const FIELDS = ['name', 'nis', 'nisn', 'birth_date', 'classroom_id'];

    /** The screen the dialog stood on (audit `source`). */
    public const SOURCES = ['kelas', 'aktivasi', 'import', 'surat', 'pengguna', 'data_siswa', 'lainnya'];

    public const SOURCE_OTHER = 'lainnya';

    public const NAME_MAX = 150;

    public const MIN_AGE = 13;

    public const MAX_AGE = 21;

    /** Error key of a refusal that is about the whole student, not one field. */
    public const KEY_STUDENT = 'student';

    public const MSG_CHANGED = 'Data siswa ini baru saja diubah. Isian sudah diperbarui.';

    public const MSG_GONE = 'Siswa ini sudah dipindahkan ke Sampah atau dihapus. Daftar sudah diperbarui.';

    public const MSG_NAME_REQUIRED = 'Nama lengkap wajib diisi.';

    public const MSG_NAME_MAX = 'Nama lengkap maksimal 150 karakter.';

    public const MSG_NAME_LETTERS = 'Nama lengkap hanya boleh berisi huruf.';

    public const MSG_NIS_FORMAT = 'NIS harus 4–20 angka.';

    public const MSG_NIS_TAKEN = 'NIS sudah dipakai siswa lain.';

    public const MSG_NIS_TRASH = 'NIS ini milik siswa di Sampah.';

    public const MSG_NISN_FORMAT = 'NISN harus 10 angka.';

    public const MSG_NISN_TAKEN = 'NISN sudah dipakai siswa lain.';

    public const MSG_NISN_TRASH = 'NISN ini milik siswa di Sampah.';

    public const MSG_BIRTH_REQUIRED = 'Tanggal lahir wajib diisi.';

    public const MSG_BIRTH_INVALID = 'Tanggal lahir tidak valid (pakai DD-MM-YYYY).';

    public const MSG_BIRTH_AGE = 'Usia harus 13–21 tahun.';

    public const MSG_CLASS_REQUIRED = 'Pilih kelas.';

    public const MSG_CLASS_UNKNOWN = 'Kelas tidak ditemukan.';

    public const MSG_CLASS_PAST = 'Tanggal arsip dari kelas ini sudah lewat.';

    public const MSG_CLASS_ARCHIVED = 'Kelas siswa alumni tidak bisa diubah.';

    private const NAME_PATTERN = "/^[\\p{L}][\\p{L} .,'’-]*$/u";

    /** The import's date formats (ImportStudents::parseDate), DD-MM-YYYY first. */
    private const DATE_FORMATS = ['d-m-Y', 'j-n-Y', 'd/m/Y', 'j/n/Y', 'd.m.Y', 'Y-m-d'];

    public function __construct(private readonly GraduationCalculator $graduation) {}

    /**
     * Saves the changed fields of one student.
     *
     * @param  array<string, mixed>  $input  name, nis, nisn, birth_date (DD-MM-YYYY), classroom_id
     * @param  array<string, string|null>|null  $shown  snapshot() of what the screen showed; null = no stale check
     * @return array{fields: list<string>, initial_password_rehashed: bool} fields = the changed FIELDS (empty: nothing written)
     *
     * @throws ValidationException field keys of FIELDS, or KEY_STUDENT when the student changed or left meanwhile
     */
    public function handle(User $actor, User $account, array $input, string $source, ?array $shown = null): array
    {
        $student = Student::query()->where('user_id', $account->getKey())->first();

        if ($student === null || $account->trashed()) {
            throw ValidationException::withMessages([self::KEY_STUDENT => self::MSG_GONE]);
        }

        Gate::forUser($actor)->authorize('update', $student);

        $source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_OTHER;
        $today = self::today();

        try {
            $result = DB::transaction(function () use ($actor, $account, $input, $source, $shown, $today): array {
                $lockedUser = User::query()->whereKey($account->getKey())->lockForUpdate()->first();
                $lockedStudent = Student::query()->where('user_id', $account->getKey())->lockForUpdate()->first();

                // Moved to the Sampah (or purged) since the dialog opened.
                if ($lockedUser === null || $lockedStudent === null) {
                    throw ValidationException::withMessages([self::KEY_STUDENT => self::MSG_GONE]);
                }

                $lockedUser->setRelation('student', $lockedStudent);

                if ($shown !== null && self::snapshot($lockedUser) !== $shown) {
                    throw ValidationException::withMessages([self::KEY_STUDENT => self::MSG_CHANGED]);
                }

                Gate::forUser($actor)->authorize('update', $lockedStudent);

                $plan = $this->plan($lockedUser, $lockedStudent, $input, $today);

                if ($plan['errors'] !== []) {
                    throw ValidationException::withMessages($plan['errors']);
                }

                if ($plan['fields'] === []) {
                    return ['fields' => [], 'initial_password_rehashed' => false, 'dates' => false];
                }

                $userValues = $plan['user'];
                $rehashed = isset($plan['student']['birth_date']) && self::onBirthDatePassword($lockedUser, $lockedStudent);

                if ($rehashed) {
                    // D-173: still the birth-date password of the old date → the one of the new date. Never shown or logged.
                    $userValues['password'] = Hash::make(InitialPassword::forBirthDate((string) $plan['student']['birth_date']));
                }

                if ($userValues !== []) {
                    // Quietly: the User model logs `name` changes with their values; the one row below names the field only.
                    $lockedUser->forceFill($userValues)->saveQuietly();
                }

                if ($plan['student'] !== []) {
                    $lockedStudent->forceFill($plan['student'])->save();
                }

                activity('student')
                    ->performedOn($lockedStudent)
                    ->causedBy($actor)
                    ->event(self::EVENT)
                    ->withProperties([
                        'fields' => $plan['fields'],
                        'source' => $source,
                        'initial_password_rehashed' => $rehashed,
                        'archive_dates_changed' => $plan['dates'],
                    ])
                    ->log(self::EVENT);

                return ['fields' => $plan['fields'], 'initial_password_rehashed' => $rehashed, 'dates' => $plan['dates']];
            });
        } catch (UniqueConstraintViolationException) {
            // Another save took the number between the check and the write (two tabs): the same field error.
            throw ValidationException::withMessages($this->uniqueErrors($account, $input));
        }

        if (array_intersect($result['fields'], ['name', 'classroom_id']) !== []) {
            PublicAchievements::flush();
        }

        return ['fields' => $result['fields'], 'initial_password_rehashed' => $result['initial_password_rehashed']];
    }

    /**
     * Every problem of the input at once (field => sentence), without writing; the dialog shows them before it asks
     * anything else. The same check runs again inside handle() on the locked rows.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function errors(User $account, array $input): array
    {
        $student = Student::query()->where('user_id', $account->getKey())->first();

        if ($student === null) {
            return [self::KEY_STUDENT => self::MSG_GONE];
        }

        return $this->plan($account, $student, $input, self::today())['errors'];
    }

    /**
     * The changed FIELDS of the input (also the invalid ones), the dialog's "Tidak ada data yang diubah." check.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function changedFields(User $account, array $input): array
    {
        $student = Student::query()->where('user_id', $account->getKey())->first();

        return $student === null ? [] : $this->plan($account, $student, $input, self::today())['fields'];
    }

    /**
     * The archive date the student gets in this class: null when the class is unknown, inactive, in the Sampah or of the
     * same tingkat (the dates stay), else the date GraduationCalculator gives for the school year of today (it may lie
     * in the past: handle() then refuses the class).
     */
    public function newArchiveDate(Student $student, string $classroomId): ?CarbonImmutable
    {
        if ($classroomId === '' || $classroomId === (string) $student->classroom_id) {
            return null;
        }

        $classroom = Classroom::query()->active()->find($classroomId);

        if ($classroom === null || $classroom->level === self::currentLevel($student)) {
            return null;
        }

        return CarbonImmutable::instance($this->graduation->calculate((int) $classroom->level, SchoolYear::containing(self::today()))->archiveAt);
    }

    /**
     * What the screen shows of the student, as strings: the stale check compares it with the locked rows. Changes of
     * these values only (a login, a new email, … leave it alone).
     *
     * @return array<string, string|null>
     */
    public static function snapshot(User $account): array
    {
        $student = $account->relationLoaded('student') ? $account->student : $account->student()->first();

        return [
            'name' => (string) $account->name,
            'status' => $account->status?->value,
            'nis' => $student?->nis !== null ? (string) $student->nis : null,
            'nisn' => $student?->nisn !== null ? (string) $student->nisn : null,
            'birth_date' => $student?->birth_date?->format('Y-m-d'),
            'classroom_id' => $student?->classroom_id !== null ? (string) $student->classroom_id : null,
            'grade12_start_date' => $student?->grade12_start_date?->format('Y-m-d'),
            'archive_at' => $student?->archive_at?->format('Y-m-d'),
        ];
    }

    /**
     * Whether the stored password is still the D-173 birth-date password of the stored birth date (a pending student who
     * never changed it), i.e. whether a new birth date re-hashes it. The conditions of ApplyBirthDateInitialPasswords.
     */
    public static function onBirthDatePassword(User $account, ?Student $student = null): bool
    {
        $student ??= Student::query()->where('user_id', $account->getKey())->first();
        $birthDate = $student?->birth_date;
        $hash = $account->getAttributes()['password'] ?? null;

        return $birthDate !== null
            && is_string($hash) && $hash !== ''
            && $account->status === UserStatus::PendingActivation
            && (bool) $account->must_change_password
            && $account->temporary_password_issued_at === null
            && Hash::check(InitialPassword::forBirthDate($birthDate->format('Y-m-d')), $hash);
    }

    /** A typed date in the import's formats (DD-MM-YYYY first), a real calendar day, else null. */
    public static function parseDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Asia/Jakarta'));

            // Round-trip check rejects overflow dates such as 31-02-2008.
            if ($date !== false && $date->format($format) === $value) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    /** The name as stored: trimmed, every run of whitespace one space. */
    public static function squish(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    /**
     * What the input changes and what is wrong with it. Only a changed field is checked; the user and student columns
     * to write (a class of another tingkat also brings import_school_year, grade12_start_date and archive_at).
     *
     * @param  array<string, mixed>  $input
     * @return array{errors: array<string, string>, fields: list<string>, user: array<string, mixed>, student: array<string, mixed>, dates: bool}
     */
    private function plan(User $account, Student $student, array $input, CarbonImmutable $today): array
    {
        $errors = [];
        $fields = [];
        $user = [];
        $row = [];
        $dates = false;

        $name = self::squish((string) ($input['name'] ?? ''));

        if ($name !== (string) $account->name) {
            $fields[] = 'name';

            if ($name === '') {
                $errors['name'] = self::MSG_NAME_REQUIRED;
            } elseif (mb_strlen($name) > self::NAME_MAX) {
                $errors['name'] = self::MSG_NAME_MAX;
            } elseif (preg_match(self::NAME_PATTERN, $name) !== 1) {
                $errors['name'] = self::MSG_NAME_LETTERS;
            } else {
                $user['name'] = $name;
            }
        }

        foreach (['nis' => '/^\d{4,20}$/', 'nisn' => '/^\d{10}$/'] as $column => $pattern) {
            $value = self::digits($input[$column] ?? '');

            if ($value === (string) $student->{$column}) {
                continue;
            }

            $fields[] = $column;

            if (preg_match($pattern, $value) !== 1) {
                $errors[$column] = $column === 'nis' ? self::MSG_NIS_FORMAT : self::MSG_NISN_FORMAT;
            } elseif (($holder = self::holder($column, $value, $student)) !== null) {
                $errors[$column] = self::takenMessage($column, $holder);
            } else {
                $row[$column] = $value;
            }
        }

        $raw = trim((string) ($input['birth_date'] ?? ''));
        $birthDate = self::parseDate($raw);
        $stored = $student->birth_date?->format('Y-m-d');

        if ($birthDate === null ? ($raw !== '' || $stored !== null) : $birthDate->format('Y-m-d') !== $stored) {
            $fields[] = 'birth_date';

            if ($birthDate === null) {
                $errors['birth_date'] = $raw === '' ? self::MSG_BIRTH_REQUIRED : self::MSG_BIRTH_INVALID;
            } else {
                $age = (int) $birthDate->diffInYears($today, true);

                if ($birthDate->greaterThan($today) || $age < self::MIN_AGE || $age > self::MAX_AGE) {
                    $errors['birth_date'] = self::MSG_BIRTH_AGE;
                } else {
                    $row['birth_date'] = $birthDate->format('Y-m-d');
                }
            }
        }

        $classroomId = trim((string) ($input['classroom_id'] ?? ''));

        if ($classroomId !== (string) $student->classroom_id) {
            $fields[] = 'classroom_id';
            $classroom = $classroomId === '' ? null : Classroom::query()->active()->find($classroomId);

            if ($account->status === UserStatus::Archived) {
                // Alumni keep their last class, as the import skips them (ImportStudents).
                $errors['classroom_id'] = self::MSG_CLASS_ARCHIVED;
            } elseif ($classroomId === '') {
                $errors['classroom_id'] = self::MSG_CLASS_REQUIRED;
            } elseif ($classroom === null) {
                $errors['classroom_id'] = self::MSG_CLASS_UNKNOWN;
            } elseif ((int) $classroom->level === self::currentLevel($student)) {
                // The same tingkat: the archive dates stay.
                $row['classroom_id'] = $classroom->getKey();
            } else {
                $year = SchoolYear::containing($today);
                $graduation = $this->graduation->calculate((int) $classroom->level, $year);

                if ($graduation->archiveAt->format('Y-m-d') <= $today->format('Y-m-d')) {
                    $errors['classroom_id'] = self::MSG_CLASS_PAST;
                } else {
                    $row += [
                        'classroom_id' => $classroom->getKey(),
                        'import_school_year' => $year->toString(),
                        'grade12_start_date' => $graduation->grade12StartDate->format('Y-m-d'),
                        'archive_at' => $graduation->archiveAt->format('Y-m-d'),
                    ];
                    $dates = $row['grade12_start_date'] !== $student->grade12_start_date?->format('Y-m-d')
                        || $row['archive_at'] !== $student->archive_at?->format('Y-m-d');
                }
            }
        }

        // In the order of FIELDS.
        $fields = array_values(array_intersect(self::FIELDS, $fields));

        return ['errors' => $errors, 'fields' => $fields, 'user' => $user, 'student' => $row, 'dates' => $dates];
    }

    /**
     * The field errors after a UniqueConstraintViolationException: the numbers held by another student now, else the
     * NISN (or the NIS) the input changed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function uniqueErrors(User $account, array $input): array
    {
        $student = Student::query()->where('user_id', $account->getKey())->first();
        $errors = [];

        foreach (['nis', 'nisn'] as $column) {
            $value = self::digits($input[$column] ?? '');

            if ($student !== null && $value !== '' && ($holder = self::holder($column, $value, $student)) !== null) {
                $errors[$column] = self::takenMessage($column, $holder);
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        return $student !== null && self::digits($input['nisn'] ?? '') === (string) $student->nisn
            ? ['nis' => self::MSG_NIS_TAKEN]
            : ['nisn' => self::MSG_NISN_TAKEN];
    }

    /** Another student (the Sampah included: the unique index still holds those rows) with this NIS or NISN. */
    private static function holder(string $column, string $value, Student $student): ?Student
    {
        return Student::withTrashed()->where($column, $value)->whereKeyNot($student->getKey())->first(['id', 'deleted_at']);
    }

    private static function takenMessage(string $column, Student $holder): string
    {
        return match (true) {
            $column === 'nis' => $holder->trashed() ? self::MSG_NIS_TRASH : self::MSG_NIS_TAKEN,
            default => $holder->trashed() ? self::MSG_NISN_TRASH : self::MSG_NISN_TAKEN,
        };
    }

    /** The tingkat of the student's class now (a class in the Sampah counts too); null without one. */
    private static function currentLevel(Student $student): ?int
    {
        if ($student->classroom_id === null) {
            return null;
        }

        $level = Classroom::withTrashed()->whereKey($student->classroom_id)->value('level');

        return $level === null ? null : (int) $level;
    }

    /** A typed number without spaces. */
    private static function digits(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', '', is_scalar($value) ? (string) $value : '');
    }

    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Jakarta')->startOfDay();
    }
}
