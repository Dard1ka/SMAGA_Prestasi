{{-- Right panel "Siapkan publikasi" ("Detail publikasi" once it is out, QA5). $panel comes from Publikasi::panel(); form state is the component's properties. --}}
<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
    <h2 id="pub-judul" class="m-0 text-lg leading-6 font-bold">{{ $panel['published'] ? 'Detail publikasi' : 'Siapkan publikasi' }}</h2>
    <x-sth.chip :tone="$panel['statusChip'][0]" size="sm">{{ $panel['statusChip'][1] }}</x-sth.chip>
</div>
<p class="m-0 text-xs font-semibold text-muted">{{ $panel['heading'] }}</p>

{{--
    M3-E: Humas designs the flyer in its own tools; STH only hands over the facts (FlyerData).
    "Salin data" copies the innerText of #data-flyer (hook data-sth-copy in app.js). So that element holds
    facts only, each row is a block and a "Label: isi" pair sits inline inside it: one fact per line.
--}}
<section class="flex flex-col gap-2 rounded-card bg-subtle p-3" aria-labelledby="data-flyer-judul">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="data-flyer-judul" class="m-0 text-md leading-[18px] font-bold">Data untuk flyer</h3>
        <x-sth.button variant="quiet" size="sm" data-sth-copy="#data-flyer">Salin data</x-sth.button>
    </div>
    <p class="m-0 text-xs leading-[17px] text-muted">Flyer dibuat di luar aplikasi ini. Pakai data di bawah supaya nama dan capaian tidak salah tulis.</p>
    <dl id="data-flyer" class="m-0 flex flex-col gap-1 text-sm leading-snug">
        @foreach ($panel['flyerRows'] as $row)
            @if ($row['list'])
                <div>
                    <dt class="text-muted">{{ $row['label'] }}:</dt>
                    @foreach ($row['values'] as $value)
                        <dd class="m-0 font-semibold">{{ $value }}</dd>
                    @endforeach
                </div>
            @else
                <div><dt class="inline text-muted">{{ $row['label'] }}:</dt> <dd class="m-0 inline font-semibold">{{ $row['values'][0] }}</dd></div>
            @endif
        @endforeach
    </dl>
    @foreach ($panel['flyerNotes'] as $note)
        <p class="m-0 flex items-start gap-1.5 text-xs leading-[17px] font-semibold text-ink-2">
            <x-sth.icon name="info" :size="14" class="mt-px" />{{ $note }}
        </p>
    @endforeach
</section>

@if ($panel['published'])
    <div class="flex flex-col gap-1 border-t border-line pt-3">
        <h3 class="m-0 text-md font-bold">Sudah terbit</h3>
        @if ($panel['publishedChannels'] !== '')
            <p class="m-0 text-sm text-ink-2">Kanal: {{ $panel['publishedChannels'] }}</p>
        @endif
        @if ($panel['publishedCaption'])
            <p class="m-0 text-xs leading-relaxed whitespace-pre-line text-ink-2">{{ $panel['publishedCaption'] }}</p>
        @endif
    </div>
@elseif ($panel['canSchedule'] || $panel['canPublish'])
    {{-- D-122: the scheduled time passed. Nothing was published by itself; Humas confirms or picks a new time. --}}
    {{-- $panel['notifies']: the student notice is promised only when "Tandai dipublikasikan" will send one (not to an alumnus, D-109). --}}
    @if ($panel['overdue'])
        <x-sth.alert tone="warning" title="Jadwal sudah lewat" wire:key="pub-lewat">
            @if ($panel['notifies'])
                Sudah di-upload ke kanal? Tekan Tandai dipublikasikan supaya siswa diberi tahu. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang.
            @else
                Sudah di-upload ke kanal? Tekan Tandai dipublikasikan. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang.
            @endif
        </x-sth.alert>
    @endif
    <form wire:submit="schedule" wire:key="pub-form" class="flex flex-col gap-3" novalidate>
        <x-sth.textarea wire:model="caption" label="Caption" rows="5" hint="Maks. 2.000 karakter." />
        <x-sth.radio-pick wire:model="channels" multiple label="Kanal" :options="$channelOptions" :value="$channels" :columns="3" size="sm" />
        <div class="grid grid-cols-2 gap-3">
            <x-sth.input type="date" wire:model="date" :value="$date" label="Tanggal publikasi" class="num" />
            <x-sth.input type="time" wire:model="time" :value="$time" label="Jam" class="num" />
        </div>
        {{-- D-122: RemindDuePublications only reminds Humas at this time. PublishPublication ("Tandai dipublikasikan") is what sets dipublikasikan and tells the student (when the account can still be told: $panel['notifies']). --}}
        @if ($panel['notifies'])
            <p class="m-0 text-xs text-muted">Jadwal ini hanya pengingat. Pada waktu ini Humas mendapat pengingat untuk meng-upload ke kanal yang dipilih. Status baru menjadi Dipublikasikan, dan siswa baru diberi tahu, setelah tombol Tandai dipublikasikan ditekan.</p>
        @else
            <p class="m-0 text-xs text-muted">Jadwal ini hanya pengingat. Pada waktu ini Humas mendapat pengingat untuk meng-upload ke kanal yang dipilih. Status baru menjadi Dipublikasikan setelah tombol Tandai dipublikasikan ditekan.</p>
        @endif
        {{-- "Tandai dipublikasikan" asks first (dialog in publikasi.blade.php, Publikasi::askPublish). An overdue row leads with it. --}}
        <div class="flex flex-wrap gap-2">
            @if ($panel['overdue'])
                @if ($panel['canPublish'])
                    <x-sth.button wire:key="pub-tandai-utama" icon="check" class="flex-1" wire:click="askPublish" wire:loading.attr="disabled" wire:target="askPublish" aria-haspopup="dialog">Tandai dipublikasikan</x-sth.button>
                @endif
                @if ($panel['canSchedule'])
                    <x-sth.button wire:key="pub-jadwal-ulang" type="submit" variant="quiet" icon="calendar" class="flex-1" wire:loading.attr="disabled" wire:target="schedule">{{ $panel['scheduleLabel'] }}</x-sth.button>
                @endif
            @else
                @if ($panel['canSchedule'])
                    <x-sth.button wire:key="pub-jadwal" type="submit" icon="calendar" class="flex-1" wire:loading.attr="disabled" wire:target="schedule">{{ $panel['scheduleLabel'] }}</x-sth.button>
                @endif
                @if ($panel['canPublish'])
                    <x-sth.button wire:key="pub-tandai" variant="quiet" icon="check" class="flex-1" wire:click="askPublish" wire:loading.attr="disabled" wire:target="askPublish" aria-haspopup="dialog">Tandai dipublikasikan</x-sth.button>
                @endif
            @endif
        </div>
    </form>
@endif

@if ($panel['canAppreciate'])
    <div class="flex flex-col gap-2 border-t border-line pt-3">
        <h3 class="m-0 text-md leading-[18px] font-bold">Apresiasi</h3>
        @foreach ($panel['appreciations'] as $appreciation)
            <p class="m-0 flex items-start gap-1.5 text-sm leading-snug font-semibold text-green">
                <x-sth.icon name="check" :size="16" class="mt-px" />
                {{-- D-123: with the day and start time of the event, e.g. "Upacara Senin (12 Okt 2026, 07.00)". --}}
                <span>Sudah masuk daftar apresiasi {{ $appreciation['event'] }} ({{ $appreciation['date'] }}{{ $appreciation['done'] ? ', selesai' : '' }})</span>
            </p>
        @endforeach
        @if ($panel['eventOptions'] !== [])
            <form wire:submit="recordAppreciation" class="flex flex-col gap-2 sm:flex-row sm:items-end" novalidate>
                <x-sth.select wire:model="eventId" label="Masukkan ke acara apresiasi" placeholder="Pilih acara" :options="$panel['eventOptions']" class="min-w-0 flex-1" />
                <x-sth.button type="submit" variant="soft" wire:loading.attr="disabled" wire:target="recordAppreciation">Masukkan</x-sth.button>
            </form>
            @if ($panel['appreciationTeam'] !== [])
                {{-- D-160: the free teammates go on the same event in the same click. --}}
                <p class="m-0 text-xs text-muted [overflow-wrap:anywhere]">Anggota tim lain ikut dimasukkan: {{ implode(', ', $panel['appreciationTeam']) }}.</p>
            @endif
        @elseif ($panel['appreciations'] === [])
            <p class="m-0 text-xs text-muted">
                Belum ada acara apresiasi mendatang.
                <a href="{{ route('humas.apresiasi.index') }}" class="font-semibold">Buat acara</a>
            </p>
        @endif
    </div>
@endif
