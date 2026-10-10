<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\UserStatus;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * QA10: the "named" rule of the public pages (QA9, D-30) for the Humas side, in one place. A student may be named
 * (publication queue, the reminder to Humas, the Humas notices) only with publish consent while neither the student record nor
 * the account is deleted and the account is not disabled. An archived alumnus with consent stays named (D-29).
 *
 * The same rule also lives in `PublicAchievements::mapRow()` and `FilePolicy::appearsByName()` (QA9, not this area's
 * files); they could call this class.
 */
final class NamedStudent
{
    /** The rule for one loaded student (the user relation is loaded when missing). */
    public static function allows(?Student $student): bool
    {
        if ($student === null || $student->deleted_at !== null || $student->publish_consent !== true) {
            return false;
        }

        // Student::user() includes deleted accounts, so the check below sees them.
        $user = $student->loadMissing('user')->user;

        return $user !== null
            && $user->deleted_at === null
            && $user->status !== UserStatus::Disabled;
    }

    /**
     * The same rule as a query constraint on students, e.g. `whereHas('student', NamedStudent::constrain(...))`.
     * The relations of Achievement and Student include deleted rows, so both are excluded here explicitly.
     *
     * @param  Builder<Student>  $students
     * @return Builder<Student>
     */
    public static function constrain(Builder $students): Builder
    {
        return $students
            ->withoutTrashed()
            ->where('publish_consent', true)
            ->whereHas('user', fn (Builder $user) => $user
                ->withoutTrashed()
                ->where('status', '!=', UserStatus::Disabled->value));
    }
}
