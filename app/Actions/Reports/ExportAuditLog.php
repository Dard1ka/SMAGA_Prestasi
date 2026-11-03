<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Users\AdminRecipients;
use App\Enums\Permission;
use App\Models\User;
use App\Notifications\Admin\LargeExportNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin › Log audit (UI-MAP §5 `admin.audit.index`, backlog #8, owner B5; SECURITY §3m-9).
 *
 * query(): the one filter definition shared by the screen and the export — actor (`pelaku`: user id or
 * "sistem"), event (`peristiwa`), subject type (`subjek`: short key, see subjectTypes()), date range
 * (`dari`/`sampai`, Y-m-d, app timezone) and `q` (actor name/username). Read-only: activity_log rows are never
 * updated or deleted (the runtime DB user may only SELECT/INSERT there, B6/§3h).
 *
 * handle(): CSV export of the filtered rows (newest first, at most MAX_ROWS), every text cell formula-safe
 * (ExportCells::csv), UTF-8 with BOM for Excel. Needs `audit.view`; the export itself is audited
 * (`audit.exported`: filters, rows, sha256) and more than LargeExportNotice::LIMIT rows alert every Admin.
 */
final class ExportAuditLog
{
    public const MAX_ROWS = 10000;

    public const HEADERS = ['ID', 'Waktu', 'Pelaku', 'ID pelaku', 'Log', 'Peristiwa', 'Subjek', 'ID subjek', 'Keterangan', 'Properti', 'Perubahan'];

    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @param  array<string, string>  $filters
     * @return array{path: string, filename: string, rows: int, truncated: bool}
     */
    public function handle(User $actor, array $filters): array
    {
        self::authorize($actor);

        $filters = self::normalize($filters);
        $total = self::query($filters)->count();
        $path = tempnam(sys_get_temp_dir(), 'audit');

        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw new RuntimeException('Berkas ekspor tidak bisa dibuat.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::HEADERS, ',', '"', '');
        $rows = 0;

        self::query($filters)
            ->with('causer')
            ->lazyByIdDesc(500)
            ->take(self::MAX_ROWS)
            ->each(function (Activity $a) use ($handle, &$rows): void {
                fputcsv($handle, array_map(ExportCells::csv(...), [
                    $a->id,
                    $a->created_at?->format('Y-m-d H:i:s'),
                    $a->causer?->name ?? ($a->causer_id === null ? 'Sistem' : '-'),
                    $a->causer_id,
                    $a->log_name,
                    $a->event,
                    self::subjectLabel($a->subject_type),
                    $a->subject_id,
                    $a->description,
                    $a->properties?->toArray() ?? [],
                    $a->attribute_changes?->toArray() ?? [],
                ]), ',', '"', '');
                $rows++;
            });

        fclose($handle);

        $truncated = $total > $rows;
        $now = CarbonImmutable::now(config('app.timezone'));

        activity('audit')
            ->causedBy($actor)
            ->event('audit.exported')
            ->withProperties([
                'filters' => array_filter($filters, fn (string $v): bool => $v !== ''),
                'rows' => $rows,
                'truncated' => $truncated,
                'sha256' => hash_file('sha256', $path),
            ])
            ->log('audit.exported');

        if ($rows > LargeExportNotice::LIMIT) {
            Notification::send($this->recipients->admins(), new LargeExportNotice((string) $actor->name, 'log audit', $rows, $now->translatedFormat('j F Y H:i')));
        }

        return ['path' => $path, 'filename' => 'log-audit-'.$now->format('Ymd-Hi').'.csv', 'rows' => $rows, 'truncated' => $truncated];
    }

    /** `audit.view` (Admin) on an account that can still log in. */
    public static function authorize(User $actor): void
    {
        if (! $actor->canLogIn()) {
            throw new AuthorizationException;
        }

        Gate::forUser($actor)->authorize(Permission::AuditView->value);
    }

    /**
     * @param  array<string, string>  $filters  pelaku, peristiwa, subjek, dari, sampai, q
     * @return Builder<Activity>
     */
    public static function query(array $filters): Builder
    {
        $f = self::normalize($filters);
        $types = self::subjectTypes();

        return Activity::query()
            ->when($f['pelaku'] === 'sistem', fn (Builder $q) => $q->whereNull('causer_id'))
            ->when(ctype_digit($f['pelaku']), fn (Builder $q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', (int) $f['pelaku']))
            ->when($f['peristiwa'] !== '', fn (Builder $q) => $q->where('event', $f['peristiwa']))
            ->when($f['subjek'] !== '' && isset($types[$f['subjek']]), fn (Builder $q) => $q->where('subject_type', $types[$f['subjek']]['class']))
            ->when($f['dari'] !== '', fn (Builder $q) => $q->where('created_at', '>=', $f['dari'].' 00:00:00'))
            ->when($f['sampai'] !== '', fn (Builder $q) => $q->where('created_at', '<', CarbonImmutable::parse($f['sampai'])->addDay()->format('Y-m-d').' 00:00:00'))
            ->when($f['q'] !== '', fn (Builder $q) => $q
                ->where('causer_type', (new User)->getMorphClass())
                ->whereIn('causer_id', User::withTrashed()
                    ->where(fn (Builder $u) => $u
                        ->where('name', 'like', '%'.addcslashes($f['q'], '%_\\').'%')
                        ->orWhere('username', 'like', '%'.addcslashes($f['q'], '%_\\').'%'))
                    ->select('id')));
    }

    /**
     * Every filter key present and well-formed; anything invalid becomes '' (no filter).
     *
     * @param  array<string, mixed>  $filters
     * @return array{pelaku: string, peristiwa: string, subjek: string, dari: string, sampai: string, q: string}
     */
    public static function normalize(array $filters): array
    {
        $get = fn (string $key): string => is_scalar($filters[$key] ?? null) ? trim((string) $filters[$key]) : '';
        $date = function (string $value): string {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return '';
            }

            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value ? $value : '';
            } catch (\Throwable) {
                return '';
            }
        };

        $pelaku = $get('pelaku');
        $dari = $date($get('dari'));
        $sampai = $date($get('sampai'));

        if ($dari !== '' && $sampai !== '' && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [
            'pelaku' => $pelaku === 'sistem' || (ctype_digit($pelaku) && strlen($pelaku) <= 18) ? $pelaku : '',
            'peristiwa' => preg_match('/^[a-z0-9_.]{1,100}$/', $get('peristiwa')) === 1 ? $get('peristiwa') : '',
            'subjek' => preg_match('/^[a-z0-9-]{1,60}$/', $get('subjek')) === 1 ? $get('subjek') : '',
            'dari' => $dari,
            'sampai' => $sampai,
            'q' => mb_substr($get('q'), 0, 100),
        ];
    }

    /**
     * Subject types present in the log: short key (kebab-case class basename) => class + Indonesian label.
     *
     * @return array<string, array{class: string, label: string}>
     */
    public static function subjectTypes(): array
    {
        /** @var Collection<int, string> $classes */
        $classes = Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type');
        $types = [];

        foreach ($classes as $class) {
            $types[str(class_basename($class))->kebab()->toString()] = ['class' => $class, 'label' => self::subjectLabel($class)];
        }

        return $types;
    }

    public static function subjectLabel(?string $class): string
    {
        if ($class === null || $class === '') {
            return '-';
        }

        return match (class_basename($class)) {
            'User' => 'Akun',
            'Student' => 'Siswa',
            'Competition' => 'Lomba',
            'Registration' => 'Pendaftaran',
            'RegistrationMember' => 'Anggota tim',
            'Letter' => 'Surat',
            'Report' => 'Laporan hasil',
            'Achievement' => 'Prestasi',
            'WeightVersion' => 'Bobot poin',
            'Setting' => 'Pengaturan',
            'TalentApplication' => 'Talenta',
            'TalentProfile' => 'Profil talenta',
            'Publication' => 'Publikasi',
            'AppreciationEvent' => 'Acara apresiasi',
            'Appreciation' => 'Apresiasi',
            'File' => 'Berkas',
            'PrincipalReplacement' => 'Ganti Kepala Sekolah',
            'RoleChangeRequest' => 'Perubahan peran',
            default => class_basename($class),
        };
    }
}
