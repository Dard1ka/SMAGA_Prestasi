{{-- Right panel "Siapkan publikasi". $panel comes from Publikasi::panel(); form state is the component's properties. --}}
@php($flyer = $panel['flyer'])
@php($style = $flyer['style'])
<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
    <h2 id="pub-judul" class="m-0 text-lg leading-6 font-bold">Siapkan publikasi</h2>
    <x-sth.chip :tone="$panel['statusChip'][0]" size="sm">{{ $panel['statusChip'][1] }}</x-sth.chip>
</div>
<p class="m-0 text-[13px] font-semibold text-muted">{{ $panel['heading'] }}</p>

{{-- Flyer preview (HTML; the PNG from "Download PNG" has the same content). --}}
<figure class="m-0 self-center">
    <div role="img" aria-label="{{ $flyer['label'] }}" class="relative aspect-4/5 w-[300px] max-w-full overflow-hidden rounded-[18px] shadow-card {{ $style['bg'] }}">
        <span class="absolute -top-[72px] -right-16 size-[210px] rounded-full {{ $style['blob1'] }}" aria-hidden="true"></span>
        <span class="absolute -bottom-[70px] -left-14 size-[180px] rounded-full {{ $style['blob2'] }}" aria-hidden="true"></span>
        <span class="absolute top-[118px] right-6 size-3 rounded-full {{ $style['dot'] }}" aria-hidden="true"></span>
        @if ($style['band'])
            <span class="absolute inset-x-0 top-0 h-1.5 bg-primary" aria-hidden="true"></span>
            <span class="absolute inset-x-0 bottom-0 h-1.5 bg-primary" aria-hidden="true"></span>
        @endif
        <div class="relative flex h-full flex-col items-center px-4 pt-5 pb-4 text-center">
            <span class="text-[11px] font-bold tracking-wide {{ $style['accent'] }}">{{ $flyer['school'] }}</span>
            <span class="mt-1.5 h-0.5 w-8 rounded-full {{ $style['rule'] }}" aria-hidden="true"></span>
            <span class="display mt-2 text-[21px] leading-tight font-extrabold tracking-wide {{ $style['accent'] }}">SELAMAT &amp; SUKSES</span>
            <span class="display mt-3 inline-flex size-[76px] shrink-0 items-center justify-center rounded-full border-[3px] text-[28px] font-extrabold {{ $style['ring'] }} {{ $style['avatar'] }}" aria-hidden="true">{{ $flyer['initials'] }}</span>
            <span class="display clamp2 mt-3 text-[18px] leading-tight font-extrabold text-ink">{{ $flyer['name'] }}</span>
            @if ($flyer['class'])
                <span class="text-[12px] font-semibold text-ink-2">{{ $flyer['class'] }}</span>
            @endif
            <span class="display mt-2 inline-flex h-7 items-center gap-1.5 rounded-full px-3 text-[14px] font-extrabold {{ $style['pill'] }}">
                <x-sth.icon name="award" :size="14" />{{ $flyer['result'] }}
            </span>
            <span class="display clamp2 mt-2 text-[15px] leading-tight font-extrabold text-ink">{{ $flyer['competition'] }}</span>
            @if ($flyer['level'])
                <span class="text-[12px] font-bold {{ $style['accent'] }}">{{ $flyer['level'] }}</span>
            @endif
            @if ($flyer['mentor'])
                <span class="mt-auto text-[10.5px] leading-snug font-semibold text-ink-2">{{ $flyer['mentor'] }}</span>
            @endif
        </div>
    </div>
</figure>

@if ($panel['canFlyer'])
    <x-sth.radio-pick wire:model.live="template" label="Template e-flyer" :options="$templateOptions" :value="$template" :columns="3" size="sm" />
    <x-sth.button variant="quiet" size="sm" icon="download" wire:click="downloadFlyer" wire:loading.attr="disabled" wire:target="downloadFlyer">Download PNG</x-sth.button>
@endif

@unless ($panel['photoConsent'])
    <p class="m-0 flex items-start gap-1.5 text-[12.5px] leading-[17px] text-muted">
        <x-sth.icon name="info" :size="14" class="mt-px" />Tanpa izin foto, flyer otomatis memakai inisial.
    </p>
@endunless

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
