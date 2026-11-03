<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * D-173 (smaga_server): moves the students who are STILL on the one configured initial password of D-138 to the
 * birth-date password "SMAGA_" + ddmmyyyy (InitialPassword::forBirthDate()), run by `php artisan sth:siswa:kata-sandi-awal`.
 *
 * A student is moved only when every condition holds: role siswa, status `pending_activation`, `must_change_password`
 * true, no temporary password of an Admin (`temporary_password_issued_at` empty), a birth date, and the stored hash
 * checks against the configured value (Hash::check). Everybody else is left alone and only counted: students without a
 * birth date, and students whose password is something else (already the birth-date password, the old D-24 pattern, a
 * password of their own). Only `users.password` is written: never `initial_password_expires_at`, the email, the status
 * or anything else, so the activation window stays what it was.
 *
 * Written per chunk of CHUNK accounts (SV2 review): the new hashes are made first (bcrypt, outside any transaction),
 * then ONE transaction locks each row, compares it again with what was checked (the same hash, the same state, so an
 * activation or an Admin reset in between wins: `changed_meanwhile`), writes the moved ones and adds one audit row
 * `student.initial_password_rule_applied` (log `account`, so it also reaches the security log) with the counts of that
 * chunk and the id of the run. So every committed password change is audited even when a run stops part-way (Ctrl+C,
 * a restart, an exception): a chunk commits with its audit row or not at all. A chunk that moved nobody writes no row.
 * Counts only: no name, NISN or password is ever returned, printed or logged; nobody is notified.
 *
 * Idempotent: a moved student no longer checks against the configured value, so a second run finds 0.
 *
 * A system Action like App\Actions\Users\ArchiveDueStudentAccounts: the operator runs it on the server, there is no
 * actor to authorize (the command refuses `--terapkan` outside APP_ENV=local without `--force`).
 */
final class ApplyBirthDateInitialPasswords
{
    public const EVENT = 'student.initial_password_rule_applied';

    /** Class label of a student without a class (should not happen: the import always sets one). */
    public const NO_CLASS = 'Tanpa kelas';

    /** Accounts read, hashed and written (one transaction, one audit row) at a time. */
    public const CHUNK = 100;

    public function __construct(private readonly InitialPassword $initialPassword) {}

    /**
     * What a run would do, writing nothing. $progress gets the number of accounts checked so far after every chunk.
     *
     * @param  (Closure(int): void)|null  $progress
     * @return array{by_class: array<string, int>, move: int, no_birth_date: int, other_password: int}
     */
    public function preview(?Closure $progress = null): array
    {
        $result = $this->run(false, $progress);
        unset($result['changed_meanwhile']);

        return $result;
    }

    /**
     * Moves the students; `changed_meanwhile` = checked, but activated, reset or changed before their row was written.
     *
     * @param  (Closure(int): void)|null  $progress
     * @return array{by_class: array<string, int>, move: int, no_birth_date: int, other_password: int, changed_meanwhile: int}
     */
    public function handle(?Closure $progress = null): array
    {
        return $this->run(true, $progress);
    }

    /** The accounts this rule may ever touch (the hash and the birth date are checked per row). */
    public static function candidates(): Builder
    {
        return User::query()
            ->role(RoleName::Siswa->value)
            ->where('status', UserStatus::PendingActivation->value)
            ->where('must_change_password', true)
            ->whereNull('temporary_password_issued_at');
    }

    /**
     * @param  (Closure(int): void)|null  $progress
     * @return array{by_class: array<string, int>, move: int, no_birth_date: int, other_password: int, changed_meanwhile: int}
     */
    private function run(bool $apply, ?Closure $progress): array
    {
        $configured = $this->initialPassword->make();
        $runId = (string) Str::ulid();
        $checked = 0;
        $result = ['by_class' => [], 'move' => 0, 'no_birth_date' => 0, 'other_password' => 0, 'changed_meanwhile' => 0];

        self::candidates()
            ->select(['id', 'password'])
            ->with(['student:id,user_id,birth_date,classroom_id', 'student.classroom:id,name'])
            ->chunkById(self::CHUNK, function (Collection $users) use ($apply, $configured, $runId, $progress, &$checked, &$result): void {
                /** @var list<array{id: int, checked: string, hash: string, class: string}> $moves */
                $moves = [];

                /** @var User $user */
                foreach ($users as $user) {
                    $birthDate = $user->student?->birth_date;

                    if ($birthDate === null) {
                        $result['no_birth_date']++;

                        continue;
                    }

                    $checkedHash = (string) $user->password;

                    if (! Hash::check($configured, $checkedHash)) {
                        $result['other_password']++;

                        continue;
                    }

                    $moves[] = [
                        'id' => (int) $user->getKey(),
                        'checked' => $checkedHash,
                        // Made before the transaction: bcrypt is slow, and the rows stay locked only for the writes.
                        'hash' => $apply ? Hash::make(InitialPassword::forBirthDate($birthDate->format('Y-m-d'))) : '',
                        'class' => $user->student->classroom?->name ?? self::NO_CLASS,
                    ];
                }

                $moved = $apply ? $this->write($moves, $runId) : array_column($moves, 'class');
                $result['changed_meanwhile'] += count($moves) - count($moved);

                foreach ($moved as $class) {
                    $result['by_class'][$class] = ($result['by_class'][$class] ?? 0) + 1;
                    $result['move']++;
                }

                $checked += $users->count();

                if ($progress !== null) {
                    $progress($checked);
                }
            });

        ksort($result['by_class'], SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    /**
     * Writes one chunk in one transaction: each row locked and compared again with what was checked (activated, reset
     * or given another password meanwhile → not written), then one counts-only audit row when somebody moved.
     *
     * @param  list<array{id: int, checked: string, hash: string, class: string}>  $moves
     * @return list<string> the class of every account written
     */
    private function write(array $moves, string $runId): array
    {
        if ($moves === []) {
            return [];
        }

        return DB::transaction(function () use ($moves, $runId): array {
            $written = [];

            foreach ($moves as $move) {
                /** @var User|null $locked */
                $locked = User::query()->whereKey($move['id'])->lockForUpdate()->first();

                if ($locked === null
                    || $locked->status !== UserStatus::PendingActivation
                    || ! $locked->must_change_password
                    || $locked->temporary_password_issued_at !== null
                    || ! hash_equals($move['checked'], (string) $locked->password)) {
                    continue;
                }

                $locked->forceFill(['password' => $move['hash']])->save();
                $written[] = $move['class'];
            }

            if ($written !== []) {
                activity('account')
                    ->event(self::EVENT)
                    ->withProperties([
                        'via' => 'cli',
                        'run' => $runId,
                        'moved' => count($written),
                        'changed_meanwhile' => count($moves) - count($written),
                    ])
                    ->log(self::EVENT);
            }

            return $written;
        });
    }
}
