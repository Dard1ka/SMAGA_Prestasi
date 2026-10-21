{{--
    Jejak Prestasi tracker, 6 phases (DESIGN-SPEC-v2 §4): Daftar · Persetujuan · Surat tugas · Lomba ·
    Laporan hasil · Poin & apresiasi. Phases before `phase` are done, after it upcoming.

    Full (Lomba saya detail; phase 5 shows the day the report was sent, there is no report deadline, D-151):
    <x-sth.tracker :phase="5" state="action"
        :dates="[1 => '29 Agu', 2 => '5 Sep', 3 => '8 Sep', 4 => '21–24 Sep']" />
    Compact (cards: 6 segments + "Tahap 5 dari 6 · Laporan hasil"):
    <x-sth.tracker :phase="2" compact />

    From a registration:  <x-sth.tracker :status="$registration->status" :dates="$dates" />
                          (phase = RegistrationStatus::phase(); menunggu_laporan = action, selesai = done)
    With its report:      <x-sth.tracker :status="$registration->status" :report="$registration->report?->status" />
                          (QA10: selesai + a rejected report = phase 5 + end: the journey stopped there without
                          points, D-34; without `report` a selesai registration stays "all done")

    Props
    - status   App\Enums\RegistrationStatus (or its value); sets phase + state
    - report   App\Enums\ReportStatus (or its value) of the registration's report; only `ditolak` changes anything
    - phase    1–6, the current phase (when no status)
    - state    state of the current phase: current (in progress, default) | action (needs the user,
               orange) | done (phase finished; phase 6 + done = all done) | upcoming (not started) |
               end (stopped here without points: red cross, the later phases are not reached)
    - dates    [phase => 'text'] or a 6-item list; shown under each label (full mode)
    - compact  segments + one line of text
    - label    aria-label of the list (default "Jejak prestasi")
--}}
@props(['status' => null, 'report' => null, 'phase' => 1, 'state' => 'current', 'dates' => [], 'compact' => false, 'label' => 'Jejak prestasi'])
@php
    $labels = [1 => 'Daftar', 2 => 'Persetujuan', 3 => 'Surat tugas', 4 => 'Lomba', 5 => 'Laporan hasil', 6 => 'Poin & apresiasi'];
    if ($status !== null) {
        $status = is_string($status) ? \App\Enums\RegistrationStatus::tryFrom($status) : $status;
        $report = is_string($report) ? \App\Enums\ReportStatus::tryFrom($report) : $report;
        if ($status instanceof \App\Enums\RegistrationStatus) {
            $phase = $status->phase();
            $state = match ($status) {
                \App\Enums\RegistrationStatus::MenungguLaporan => 'action',
                \App\Enums\RegistrationStatus::Selesai => 'done',
                default => 'current',
            };
            // QA10: a rejected report ends the registration as selesai without points (D-34): stop at Laporan hasil.
            if ($status === \App\Enums\RegistrationStatus::Selesai && $report === \App\Enums\ReportStatus::Ditolak) {
                $phase = 5;
                $state = 'end';
            }
        }
    }
    $phase = min(max((int) $phase, 1), 6);
    $currentState = ['current' => 'cur', 'action' => 'act', 'done' => 'done', 'upcoming' => 'up', 'end' => 'end'][$state] ?? 'cur';
    $ended = $currentState === 'end';
    $endText = $phase === 5 ? 'Laporan ditolak, tanpa poin' : $labels[$phase].', berhenti di sini';
    $dates = array_is_list($dates) ? array_combine(range(1, max(count($dates), 1)), $dates ?: ['']) : $dates;
    $srText = ['done' => 'selesai', 'cur' => 'tahap saat ini', 'act' => 'tahap saat ini, perlu tindakan', 'up' => 'belum dimulai', 'end' => mb_strtolower($endText)];
    $steps = [];
    foreach ($labels as $n => $text) {
        $st = $n < $phase ? 'done' : ($n === $phase ? $currentState : 'up');
        $date = $dates[$n] ?? null;
        // After an end the later phases are never reached; the last one says why.
        if ($ended && $n === 6 && $n > $phase) {
            $date ??= 'Tanpa poin';
        }
        $steps[$n] = ['n' => $n, 'label' => $text, 'state' => $st, 'date' => $date, 'sr' => $ended && $n > $phase ? 'tidak dicapai' : $srText[$st]];
    }
    $segment = ['done' => 'sg-done', 'cur' => 'sg-cur', 'act' => 'sg-act', 'end' => 'bg-red', 'up' => null];
@endphp
@if ($compact)
    <div {{ $attributes->class('flex flex-col gap-2') }}>
        <div class="sg-row" aria-hidden="true">
            @foreach ($steps as $step)
                <span @class(['sg', $segment[$step['state']]])></span>
            @endforeach
        </div>
        <p class="m-0 text-xs leading-[1.3] font-semibold text-ink-2">
            Tahap <span class="num">{{ $phase }}</span> dari 6 · {{ $ended ? $endText : $labels[$phase] }}@if ($currentState === 'act')<span class="sr-only">, perlu tindakan</span>@endif
        </p>
    </div>
@else
    <ol aria-label="{{ $label }}" {{ $attributes->class('trk') }}>
        @foreach ($steps as $step)
            <li class="trk-step s-{{ $step['state'] }}" @if (in_array($step['state'], ['cur', 'act'], true)) aria-current="step" @endif>
                <span class="trk-line" aria-hidden="true"></span>
                <span @class(['trk-dot', 'bg-red-bg text-red' => $step['state'] === 'end']) aria-hidden="true">
                    @if ($step['state'] === 'done')
                        <x-sth.icon name="check" :size="16" />
                    @elseif ($step['state'] === 'end')
                        <x-sth.icon name="x" :size="16" />
                    @else
                        <span class="num">{{ $step['n'] }}</span>
                    @endif
                </span>
                <span class="trk-text">
                    <span @class(['trk-lbl', 'font-bold text-ink' => $step['state'] === 'end'])>{{ $step['label'] }}</span>
                    @if ($step['date'])
                        <span class="trk-date num">{{ $step['date'] }}</span>
                    @endif
                    <span class="sr-only">, {{ $step['sr'] }}</span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
