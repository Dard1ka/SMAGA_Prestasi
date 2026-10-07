{{-- Right panel "Siapkan publikasi" ("Detail publikasi" once it is out, QA5). $panel comes from Publikasi::panel(); form state is the component's properties. --}}
<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
    <h2 id="pub-judul" class="m-0 text-lg leading-6 font-bold">{{ $panel['published'] ? 'Detail publikasi' : 'Siapkan publikasi' }}</h2>
    <x-sth.chip :tone="$panel['statusChip'][0]" size="sm">{{ $panel['statusChip'][1] }}</x-sth.chip>
</div>
<p class="m-0 text-[13px] font-semibold text-muted">{{ $panel['heading'] }}</p>

{{--
    M3-E: Humas designs the flyer in its own tools; STH only hands over the facts (FlyerData).
    "Salin data" copies the innerText of #data-flyer (hook data-sth-copy in app.js). So that element holds
    facts only, each row is a block and a "Label: isi" pair sits inline inside it: one fact per line.
--}}
<section class="flex flex-col gap-2 rounded-card bg-subtle p-3" aria-labelledby="data-flyer-judul">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="data-flyer-judul" class="m-0 text-[15px] leading-[18px] font-bold">Data untuk flyer</h3>
        <x-sth.button variant="quiet" size="sm" data-sth-copy="#data-flyer">Salin data</x-sth.button>
    </div>
    <p class="m-0 text-[12.5px] leading-[17px] text-muted">Flyer dibuat di luar aplikasi ini. Pakai data di bawah supaya nama dan capaian tidak salah tulis.</p>
    <dl id="data-flyer" class="m-0 flex flex-col gap-1 text-[13.5px] leading-snug">
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
        <p class="m-0 flex items-start gap-1.5 text-[12.5px] leading-[17px] font-semibold text-ink-2">
            <x-sth.icon name="info" :size="14" class="mt-px" />{{ $note }}
        </p>
    @endforeach
</section>

@if ($panel['published'])
    <div class="flex flex-col gap-1 border-t border-line pt-3">
        <h3 class="m-0 text-[15px] font-bold">Sudah terbit</h3>
        @if ($panel['publishedChannels'] !== '')
            <p class="m-0 text-sm text-ink-2">Kanal: {{ $panel['publishedChannels'] }}</p>
        @endif
        @if ($panel['publishedCaption'])
            <p class="m-0 text-[13px] leading-relaxed whitespace-pre-line text-ink-2">{{ $panel['publishedCaption'] }}</p>
        @endif
    </div>
@elseif ($panel['canSchedule'] || $panel['canPublish'])
    <form wire:submit="schedule" class="flex flex-col gap-3" novalidate>
        <x-sth.textarea wire:model="caption" label="Caption" rows="5" hint="Maks. 2.000 karakter." />
        <x-sth.radio-pick wire:model="channels" multiple label="Kanal" :options="$channelOptions" :value="$channels" :columns="3" size="sm" />
        <div class="grid grid-cols-2 gap-3">
            <x-sth.input type="date" wire:model="date" :value="$date" label="Tanggal publikasi" class="num" />
            <x-sth.input type="time" wire:model="time" :value="$time" label="Jam" class="num" />
        </div>
        {{-- B3 (#12): AutoPublishPublications flips terjadwal → dipublikasikan at this time and reminds Humas. --}}
        <p class="m-0 text-[13px] text-muted">Pada waktu ini status berubah otomatis menjadi Dipublikasikan dan Humas mendapat pengingat untuk meng-upload ke kanal yang dipilih.</p>
        <div class="flex flex-wrap gap-2">
            @if ($panel['canSchedule'])
                <x-sth.button type="submit" icon="calendar" class="flex-1" wire:loading.attr="disabled" wire:target="schedule">{{ $panel['scheduleLabel'] }}</x-sth.button>
            @endif
            @if ($panel['canPublish'])
                <x-sth.button variant="quiet" icon="check" class="flex-1" wire:click="publish" wire:confirm="Tandai publikasi ini sudah terbit? Siswa akan diberi tahu." wire:loading.attr="disabled" wire:target="publish">Tandai dipublikasikan</x-sth.button>
            @endif
        </div>
    </form>
@endif

@if ($panel['canAppreciate'])
    <div class="flex flex-col gap-2 border-t border-line pt-3">
        <h3 class="m-0 text-[15px] leading-[18px] font-bold">Apresiasi</h3>
        @foreach ($panel['appreciations'] as $appreciation)
            <p class="m-0 flex items-start gap-1.5 text-[13.5px] leading-snug font-semibold text-green">
                <x-sth.icon name="check" :size="16" class="mt-px" />
                <span>Sudah masuk daftar apresiasi {{ $appreciation['event'] }}@if ($appreciation['done']) (selesai)@endif</span>
            </p>
        @endforeach
        @if ($panel['eventOptions'] !== [])
            <form wire:submit="recordAppreciation" class="flex flex-col gap-2 sm:flex-row sm:items-end" novalidate>
                <x-sth.select wire:model="eventId" label="Masukkan ke acara apresiasi" placeholder="Pilih acara" :options="$panel['eventOptions']" class="min-w-0 flex-1" />
                <x-sth.button type="submit" variant="soft" wire:loading.attr="disabled" wire:target="recordAppreciation">Masukkan</x-sth.button>
            </form>
        @elseif ($panel['appreciations'] === [])
            <p class="m-0 text-[13px] text-muted">
                Belum ada acara apresiasi mendatang.
                <a href="{{ route('humas.apresiasi.index') }}" class="font-semibold">Buat acara</a>
            </p>
        @endif
    </div>
@endif
