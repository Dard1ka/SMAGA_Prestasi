<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfRenderer;
use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * D-141: "Cetak daftar" on an appreciation event. Renders `resources/pdf/daftar-apresiasi.blade.php` through the
 * locked-down PdfRenderer: the students who may be named (AppreciationList / NamedStudent), no NIS, NISN or points.
 * The audit row keeps the event id and the row and omitted counts, never a name.
 */
final class PrintAppreciationList
{
    public const AUDIT_EVENT = 'appreciation.list_printed';

    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @return array{event: array{name: string, when: string}, rows: list<array<string, int|string>>, omitted: int, tpl: LetterTemplate, printedAt: string}
     */
    public static function viewData(AppreciationEvent $event): array
    {
        $list = AppreciationList::for($event);

        return [
            'event' => ['name' => $event->name, 'when' => EventWhen::long($event)],
            'rows' => $list['rows'],
            'omitted' => $list['omitted'],
            'tpl' => LetterTemplate::current(),
            'printedAt' => CarbonImmutable::now()->locale('id')->translatedFormat('j F Y, H.i').' WIB',
        ];
    }

    /** "daftar-apresiasi-<event slug, at most 60 characters>.pdf"; "acara" for a name without letters or digits. */
    public static function fileName(AppreciationEvent $event): string
    {
        $slug = rtrim(Str::limit(Str::slug($event->name), 60, ''), '-');

        return 'daftar-apresiasi-'.($slug !== '' ? $slug : 'acara').'.pdf';
    }

    public function handle(User $actor, AppreciationEvent $event): PdfDocument
    {
        Gate::forUser($actor)->authorize('printList', $event);

        $data = self::viewData($event);
        $document = $this->pdf->render('daftar-apresiasi', $data);

        activity('appreciation')
            ->performedOn($event)
            ->causedBy($actor)
            ->event(self::AUDIT_EVENT)
            ->withProperties([
                'event_id' => (string) $event->getKey(),
                'rows' => count($data['rows']),
                'omitted' => $data['omitted'],
            ])
            ->log(self::AUDIT_EVENT);

        return $document;
    }
}
