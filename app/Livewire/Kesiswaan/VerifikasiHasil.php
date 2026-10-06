<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Reports\RejectReport;
use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\VerifyReport;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Domain\Points\WeightTable;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Http\Controllers\FileController;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Registration;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Verifikasi hasil (UI-MAP #17, board Kesiswaan-Verifikasi-Hasil). Queue of `dikirim` reports on the left,
 * detail with evidence, capaian decision, live point formula, 4-item checklist and the three decisions on
 * the right. Rules live in VerifyReport / RequestReportRevision / RejectReport; the policy denies a verifier
 * who is the registration's guru pembimbing (§3c-10). "Buka" on an evidence file shows it in the preview
 * popup (M3-C, `data-sth-preview`); nothing is downloaded until "Download" is pressed there.
 */
#[Title('Verifikasi hasil')]
#[Layout('layouts::app', ['subtitle' => 'Periksa bukti, tetapkan capaian, lalu beri poin.'])]
class VerifikasiHasil extends Component
{
    use WithKesiswaanBadges;

    #[Locked]
    public ?string $selectedId = null;

    public string $finalResult = '';

    /** @var array<string, bool> */
    public array $checks = [];

    public string $note = '';

    /** Result banner of the last decision: tone, title, text. @var array<string, string>|null */
    #[Locked]
    public ?array $decision = null;

    public function mount(?Report $report = null): void
    {
        $this->authorize('viewQueue', Report::class);

        if ($report !== null) {
            $this->authorize('view', $report);
            $this->selectReport($report);

            return;
        }

        $first = $this->queueQuery()->first();

        if ($first !== null) {
            $this->selectReport($first);
        }
    }

    public function select(string $id): void
    {
        $report = $this->find($id);
        $this->authorize('view', $report);
        $this->decision = null;
        $this->selectReport($report);
    }

    public function verify(VerifyReport $action): void
    {
        $this->resetErrorBag();
        $report = $this->selected();
        $result = ResultType::tryFrom($this->finalResult);

        if ($result === null) {
            $this->addError('finalResult', 'Pilih capaian akhir.');

            return;
        }

        try {
            $achievements = $action->handle($this->actor(), $report, $result, $this->checks, $this->note);
        } catch (ValidationException $e) {
            $this->showErrors($e);

            return;
        }

        $points = (new PointFormatter)->format((string) $achievements->first()?->points);
        $this->afterDecision('green', 'Terverifikasi · '.$points.' poin'.($achievements->count() > 1 ? ' untuk tiap anggota' : ''), 'Masuk antrean Humas dan peringkat.');
    }

    public function requestRevision(RequestReportRevision $action): void
    {
        $this->resetErrorBag();
        try {
            $action->handle($this->actor(), $this->selected(), $this->note);
        } catch (ValidationException $e) {
            $this->showErrors($e);

            return;
        }

        $this->afterDecision('orange', 'Revisi diminta', 'Siswa menerima catatan kamu dan bisa mengirim ulang.');
    }

    public function reject(RejectReport $action): void
    {
        $this->resetErrorBag();
        try {
            $action->handle($this->actor(), $this->selected(), $this->note);
        } catch (ValidationException $e) {
            $this->showErrors($e);

            return;
        }

        $this->afterDecision('red', 'Laporan ditolak', 'Siswa menerima alasan penolakan.');
    }

    public function render(PointCalculator $calculator, PointFormatter $formatter): View
    {
        $weights = WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults();
        $queue = $this->queueQuery()->get();
        $report = $this->selectedId !== null ? $this->loadDetail($this->selectedId) : null;

        return view('livewire.kesiswaan.verifikasi-hasil', [
            'queue' => $queue->map(fn (Report $r): array => $this->queueItem($r)),
            'detail' => $report !== null ? $this->detail($report, $weights, $calculator, $formatter) : null,
            'resultOptions' => collect(ResultType::cases())->mapWithKeys(fn (ResultType $r): array => [
                $r->value => ['label' => $r->label(), 'sub' => $formatter->formatWeight($weights->result($r))],
            ])->all(),
            'checkLabels' => VerifyReport::CHECKS,
            'checkCount' => count(array_filter($this->checks)),
        ])->layoutData(['badges' => $this->kesiswaanBadges()]);
    }

    /** @return Builder<Report> */
    private function queueQuery()
    {
        return Report::query()
            ->awaitingVerification()
            ->with(['registration' => fn ($q) => $q->withTrashed()->with(['competition' => fn ($c) => $c->withTrashed(), 'leader.user', 'leader.classroom'])->withCount('members')])
            ->orderBy('submitted_at');
    }

    /** @return array<string, mixed> */
    private function queueItem(Report $r): array
    {
        $registration = $r->registration;
        $competition = $registration?->competition;
        $leader = $registration?->leader;
        $others = max(0, (int) ($registration?->members_count ?? 1) - 1);

        return [
            'id' => $r->getKey(),
            'name' => $competition?->name ?? 'Lomba',
            'bidang' => $competition?->bidang,
            'student' => trim(($leader?->user?->name ?? 'Siswa').($leader?->classroom ? ' · '.$leader->classroom->name : '')).($others > 0 ? ' +'.$others : ''),
            'claim' => $r->claimed_result->label().' · '.($competition?->level?->shortLabel() ?? '–'),
            'when' => self::whenLabel($r->submitted_at),
            'selected' => $r->getKey() === $this->selectedId,
        ];
    }

    private function loadDetail(string $id): ?Report
    {
        return Report::query()
            ->with([
                'registration' => fn ($q) => $q->withTrashed()->with([
                    'competition' => fn ($c) => $c->withTrashed(),
                    'mentor',
                    'members' => fn ($m) => $m->whereNull('declined_at')->orderBy('created_at'),
                    'members.student.user',
                    'members.student.classroom',
                ]),
                'files.file',
            ])
            ->find($id);
    }

    /** @return array<string, mixed> */
    private function detail(Report $report, WeightTable $weights, PointCalculator $calculator, PointFormatter $formatter): array
    {
        /** @var Registration|null $registration */
        $registration = $report->registration;
        $competition = $registration?->competition;
        $result = ResultType::tryFrom($this->finalResult) ?? $report->final_result ?? $report->claimed_result;
        $members = $registration?->members ?? collect();
        $canCompute = $competition?->level !== null && $competition?->weight_category !== null;
        $points = $canCompute ? $calculator->breakdown($weights, $competition->level, $result, $competition->weight_category)->points : null;

        return [
            'id' => $report->getKey(),
            'name' => $competition?->name ?? 'Lomba',
            'bidang' => $competition?->bidang,
            'tingkat' => $competition?->level?->shortLabel() ?? '–',
            'kategori' => $competition?->weight_category?->value ?? '–',
            'organizer' => $competition?->organizer_name,
            'status' => [$report->status->tone(), $report->status === ReportStatus::Dikirim ? 'Laporan baru' : $report->status->label()],
            'members' => $members->map(fn ($m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'class' => $m->student?->classroom?->name,
                'leader' => $m->isLeader(),
            ])->values()->all(),
            'team' => $members->count() > 1,
            'reportedAt' => self::shortDate($report->submitted_at),
            'mentor' => $registration?->mentor?->name,
            'branch' => $registration?->branch,
            'files' => $report->files->map(fn (ReportFile $f): array => [
                'kind' => $f->kind->label(),
                'name' => $f->file?->original_name ?? 'Berkas',
                'size' => $f->file !== null ? self::fileSize($f->file->size) : '',
                'isImage' => $f->file?->isImage() ?? false,
                'url' => $f->file !== null && Route::has('files.show') ? route('files.show', $f->file) : null,
                // "Buka" shows the file in the preview popup (data-sth-preview); the link itself stays the download.
                'preview' => $f->file !== null ? FileController::previewUrl($f->file) : null,
                'previewType' => $f->file !== null ? FileController::previewType($f->file) : null,
                'thumb' => $f->file !== null && FileController::previewType($f->file) === FileController::PREVIEW_IMAGE
                    ? FileController::previewUrl($f->file)
                    : null,
            ])->values()->all(),
            'researchTitle' => $report->research_title,
            'researchAbstract' => $report->research_abstract,
            'claim' => $report->claimed_result->label(),
            'changed' => $result !== $report->claimed_result,
            'resultLabel' => $result->label(),
            'wLevel' => $canCompute ? $weights->level($competition->level) : null,
            'wResult' => $weights->result($result),
            'wOrganizer' => $canCompute ? $weights->organizer($competition->weight_category) : null,
            'points' => $points !== null ? $formatter->format($points) : null,
            'pointsLabel' => $members->count() > 1 ? 'Poin tiap anggota' : 'Poin',
            'studentNote' => $report->note,
            'open' => $report->status === ReportStatus::Dikirim,
            'canDecide' => $this->actor()->can('verify', $report),
            'ownMentee' => $registration?->mentor_id !== null && (int) $registration->mentor_id === (int) $this->actor()->getKey(),
        ];
    }

    private function selectReport(Report $report): void
    {
        $this->selectedId = $report->getKey();
        $this->finalResult = ($report->final_result ?? $report->claimed_result)->value;
        $this->checks = array_fill_keys(array_keys(VerifyReport::CHECKS), false);
        $this->note = '';
        $this->resetErrorBag();
    }

    private function afterDecision(string $tone, string $title, string $text): void
    {
        $this->decision = ['tone' => $tone, 'title' => $title, 'text' => $text];
        $this->dispatch('sth-toast', message: $title.'.', tone: 'success');

        $next = $this->queueQuery()->first();

        if ($next !== null) {
            $this->selectReport($next);
        } else {
            $this->note = '';
            $this->checks = array_fill_keys(array_keys(VerifyReport::CHECKS), false);
        }
    }

    private function showErrors(ValidationException $e): void
    {
        $this->resetErrorBag();

        foreach ($e->errors() as $key => $messages) {
            $this->addError(in_array($key, ['note', 'checks'], true) ? $key : 'decision', (string) ($messages[0] ?? 'Periksa isian.'));
        }
    }

    private function selected(): Report
    {
        abort_if($this->selectedId === null, 404);

        return $this->find($this->selectedId);
    }

    private function find(string $id): Report
    {
        $report = Report::query()->find($id);

        abort_if($report === null, 404);

        return $report;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
