{{--
    Jejak Prestasi tracker, 6 phases (DESIGN-SPEC-v2 §4): Daftar · Persetujuan · Surat tugas · Lomba ·
    Laporan hasil · Poin & apresiasi. Phases before `phase` are done, after it upcoming.

    Full (Lomba saya detail):
    <x-sth.tracker :phase="5" state="action"
        :dates="[1 => '29 Agu', 2 => '5 Sep', 3 => '8 Sep', 4 => '21–24 Sep', 5 => 'Batas 24 Okt']" />
    Compact (cards: 6 segments + "Tahap 5 dari 6 · Laporan hasil"):
    <x-sth.tracker :phase="2" compact />

    From a registration:  <x-sth.tracker :status="$registration->status" :dates="$dates" />
                          (phase = RegistrationStatus::phase(); menunggu_laporan = action, selesai = done)

    Props
    - status   App\Enums\RegistrationStatus (or its value); sets phase + state
    - phase    1–6, the current phase (when no status)
    - state    state of the current phase: current (in progress, default) | action (needs the user,
               orange) | done (phase finished; phase 6 + done = all done) | upcoming (not started)
    - dates    [phase => 'text'] or a 6-item list; shown under each label (full mode)
    - compact  segments + one line of text
    - label    aria-label of the list (default "Jejak prestasi")
--}}
@props(['status' => null, 'phase' => 1, 'state' => 'current', 'dates' => [], 'compact' => false, 'label' => 'Jejak prestasi'])
@php
    $labels = [1 => 'Daftar', 2 => 'Persetujuan', 3 => 'Surat tugas', 4 => 'Lomba', 5 => 'Laporan hasil', 6 => 'Poin & apresiasi'];
    if ($status !== null) {
        $status = is_string($status) ? \App\Enums\RegistrationStatus::tryFrom($status) : $status;
        if ($status instanceof \App\Enums\RegistrationStatus) {
            $phase = $status->phase();
            $state = match ($status) {
                \App\Enums\RegistrationStatus::MenungguLaporan => 'action',
                \App\Enums\RegistrationStatus::Selesai => 'done',
                default => 'current',
            };
        }
    }
    $phase = min(max((int) $phase, 1), 6);
    $currentState = ['current' => 'cur', 'action' => 'act', 'done' => 'done', 'upcoming' => 'up'][$state] ?? 'cur';
    $dates = array_is_list($dates) ? array_combine(range(1, max(count($dates), 1)), $dates ?: ['']) : $dates;
    $srText = ['done' => 'selesai', 'cur' => 'tahap saat ini', 'act' => 'tahap saat ini, perlu tindakan', 'up' => 'belum dimulai'];
    $steps = [];
    foreach ($labels as $n => $text) {
        $st = $n < $phase ? 'done' : ($n === $phase ? $currentState : 'up');
        $steps[$n] = ['n' => $n, 'label' => $text, 'state' => $st, 'date' => $dates[$n] ?? null];
    }
    $segment = ['done' => 'sg-done', 'cur' => 'sg-cur', 'act' => 'sg-act', 'up' => null];
@endphp
@if ($compact)
    <div {{ $attributes->class('flex flex-col gap-2') }}>
        <div class="sg-row" aria-hidden="true">
            @foreach ($steps as $step)
                <span @class(['sg', $segment[$step['state']]])></span>
            @endforeach
        </div>
        <p class="m-0 text-xs leading-[1.3] font-semibold text-ink-2">
            Tahap <span class="num">{{ $phase }}</span> dari 6 · {{ $labels[$phase] }}@if ($currentState === 'act')<span class="sr-only">, perlu tindakan</span>@endif
        </p>
    </div>
@else
    <ol aria-label="{{ $label }}" {{ $attributes->class('trk') }}>
        @foreach ($steps as $step)
            <li class="trk-step s-{{ $step['state'] }}" @if (in_array($step['state'], ['cur', 'act'], true)) aria-current="step" @endif>
                <span class="trk-line" aria-hidden="true"></span>
                <span class="trk-dot" aria-hidden="true">
                    @if ($step['state'] === 'done')
                        <x-sth.icon name="check" :size="16" />
                    @else
                        <span class="num">{{ $step['n'] }}</span>
                    @endif
                </span>
                <span class="trk-text">
                    <span class="trk-lbl">{{ $step['label'] }}</span>
                    @if ($step['date'])
                        <span class="trk-date num">{{ $step['date'] }}</span>
                    @endif
                    <span class="sr-only">, {{ $srText[$step['state']] }}</span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
