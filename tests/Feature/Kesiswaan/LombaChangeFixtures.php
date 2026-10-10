<?php

declare(strict_types=1);

namespace Tests\Feature\Kesiswaan;

use App\Actions\Competitions\CreateCompetition;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * Fixtures of the "published lomba changed" notice (lead, 2026-10-10, D-126): LombaChangeNoticeTest (the rule in
 * UpdateCompetition) and LombaChangeNoticeScreenTest (the Kesiswaan drawer, the mail and the bell row).
 * Fictional data only. Tests run with "today" = 6 Oct 2026.
 */
final class LombaChangeFixtures
{
    public const NAME = 'National Science and Invention Fair (NSIF) 2026';

    /** A published, complete lomba: batas daftar 20 Okt, lomba 7–8 Nov 2026, luring di Kota Semarang, tim maks 3. */
    public static function lomba(array $attributes = []): Competition
    {
        return KesiswaanFixtures::competition(array_merge([
            'name' => self::NAME,
            'organizer_name' => 'Penyelenggara swasta',
            'organizer_type' => 'perusahaan_swasta',
            'event_status' => 'non_pemerintah',
            'mode' => 'luring',
            'location' => 'Kota Semarang',
            'participation' => Participation::Keduanya,
            'max_team_size' => 3,
            'registration_opens_at' => '2026-09-15 00:00:00',
            'registration_closes_at' => '2026-10-20 23:59:59',
            'starts_on' => '2026-11-07',
            'final_on' => '2026-11-08',
            'website_url' => 'https://example.org/nsif',
            'registration_url' => 'https://example.org/nsif/daftar',
        ], $attributes))->fresh();
    }

    /** The drawer's form values of a lomba (what "Ubah lomba" fills in). @return array<string, mixed> */
    public static function input(Competition $competition, array $overrides = []): array
    {
        $form = [];

        foreach (array_keys(CreateCompetition::rules()) as $field) {
            $value = $competition->getAttribute($field);
            $form[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return array_merge($form, $overrides);
    }

    /** One registration of the lomba; the ketua is a confirmed member (factory). */
    public static function registration(
        Competition $competition,
        RegistrationStatus $status,
        Student $leader,
        ?User $mentor = null,
        RegistrationType $type = RegistrationType::Tim,
    ): Registration {
        return Registration::factory()->create([
            'competition_id' => $competition->getKey(),
            'leader_student_id' => $leader->getKey(),
            'type' => $type,
            'mentor_id' => $mentor?->getKey(),
            'status' => $status,
        ])->fresh();
    }

    /** An anggota row: 'confirmed', 'invited' (no answer yet) or 'declined'. */
    public static function member(Registration $registration, Student $student, string $state = 'confirmed'): RegistrationMember
    {
        $row = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $student->getKey(), 'invited_at' => now()]);
        $row->forceFill([
            'role' => MemberRole::Anggota,
            'confirmed_at' => $state === 'confirmed' ? now() : null,
            'declined_at' => $state === 'declined' ? now() : null,
        ])->save();

        return $row;
    }

    /** The properties of every `competition.facts_changed` row, oldest first. @return list<array<string, mixed>> */
    public static function auditRows(): array
    {
        return Activity::query()->where('event', 'competition.facts_changed')->orderBy('id')->get()
            ->map(fn (Activity $row): array => $row->properties->toArray())
            ->all();
    }
}
