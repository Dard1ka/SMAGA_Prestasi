<?php

declare(strict_types=1);

namespace App\Actions\Templates;

use App\Actions\Users\AdminRecipients;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\LetterTemplateChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * TU › Template surat › "Simpan template" (backlog #7, owner B5). `letter.generate` (TU) or `setting.manage`
 * (Admin) via SettingPolicy::manageLetterTemplate. Validates the plain-text fields (LetterTemplate::validate:
 * lengths, required, only the allowed {codes}), stores them as a new version in `settings.letter_template`
 * under a row lock, and audits `letter_template.updated` (version, changed fields from/to, full snapshot for
 * "Pakai lagi"). No change → no new version. The Kepala Sekolah (letters are signed in their name) and every
 * Admin are notified. Already issued letters never change: their PDFs were rendered at issue time.
 */
final class UpdateLetterTemplate
{
    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): LetterTemplate
    {
        Gate::forUser($actor)->authorize('manageLetterTemplate', Setting::class);

        $values = LetterTemplate::validate($input);

        $result = DB::transaction(function () use ($actor, $values): ?LetterTemplate {
            Setting::query()->whereKey(LetterTemplate::SETTING_KEY)->lockForUpdate()->first();
            $current = LetterTemplate::current();

            $changed = [];
            foreach ($values as $field => $value) {
                if ($current->values[$field] !== $value) {
                    $changed[$field] = ['from' => $current->values[$field], 'to' => $value];
                }
            }

            if ($changed === []) {
                return null;
            }

            $version = max(0, $current->version) + 1;
            $at = now()->toIso8601String();

            Setting::put(LetterTemplate::SETTING_KEY, [
                'version' => $version,
                'values' => $values,
                'updated_at' => $at,
                'updated_by' => $actor->getKey(),
                'updated_by_name' => (string) $actor->name,
            ]);

            activity('setting')
                ->causedBy($actor)
                ->event('letter_template.updated')
                ->withProperties(['version' => $version, 'changed' => $changed, 'snapshot' => $values])
                ->log('letter_template.updated');

            return new LetterTemplate($version, $values, $at, (string) $actor->name);
        });

        if ($result === null) {
            return LetterTemplate::current();
        }

        Notification::send(
            $this->recipients->principalsAndAdmins()->reject(fn (User $u) => $u->is($actor))->values(),
            new LetterTemplateChanged((string) $actor->name, $result->version),
        );

        return $result;
    }
}
