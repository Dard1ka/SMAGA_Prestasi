<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\FilePurpose;
use App\Models\File;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Humas "Unduh PNG": renders the e-flyer (FlyerRenderer, 1080×1350) and stores it on the PRIVATE `local`
 * disk under flyer/<Y>/<m>/<ulid>.png with a `files` row (purpose flyer, owner = actor). The previous
 * flyer of the publication (row + stored file) is replaced. Never written to the public disk; downloads go
 * through the policy-checked `files.show` or straight back to the Humas user. Audited.
 */
final class GenerateFlyer
{
    public const DISK = 'local';

    public function __construct(private readonly FlyerRenderer $renderer) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Publication $publication, string $template = FlyerRenderer::DEFAULT_TEMPLATE): File
    {
        Gate::forUser($actor)->authorize('generateFlyer', $publication);

        if (! FlyerRenderer::isTemplate($template)) {
            throw ValidationException::withMessages(['template' => 'Pilih template flyer yang tersedia.']);
        }

        $publication->loadMissing('achievement');
        $facts = AchievementFacts::of($publication->achievement);

        $bytes = $this->renderer->render($facts, $template);
        $path = sprintf('flyer/%s/%s.png', now()->format('Y/m'), Str::lower((string) Str::ulid()));
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($path, $bytes)) {
            throw ValidationException::withMessages(['template' => 'Flyer gagal disimpan. Coba lagi.']);
        }

        try {
            [$file, $oldPath] = DB::transaction(function () use ($actor, $publication, $bytes, $path, $facts, $template): array {
                /** @var Publication $locked */
                $locked = Publication::query()->whereKey($publication->getKey())->lockForUpdate()->firstOrFail();

                $file = new File;
                $file->forceFill([
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => 'flyer-'.$facts->slug().'.png',
                    'mime' => 'image/png',
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'purpose' => FilePurpose::Flyer,
                    'owner_user_id' => $actor->getKey(),
                ])->save();

                $old = $locked->flyer_file_id !== null ? File::query()->whereKey($locked->flyer_file_id)->first() : null;

                $locked->forceFill(['flyer_file_id' => $file->getKey()])->save();
                $old?->delete();

                activity('publication')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('publication.flyer_generated')
                    ->withProperties([
                        'achievement_id' => $locked->achievement_id,
                        'file_id' => $file->getKey(),
                        'template' => $template,
                        'replaced_file_id' => $old?->getKey(),
                    ])
                    ->log('publication.flyer_generated');

                $publication->setRawAttributes($locked->getAttributes(), true);

                return [$file, $old !== null && $old->disk === self::DISK ? $old->path : null];
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $path) {
            $disk->delete($oldPath);
        }

        return $file;
    }
}
