{{-- Siswa · Undangan tim (confirm/decline). Data + rules: App\Livewire\Siswa\KonfirmasiTim. --}}
{{-- The root element comes first, with no @if around it: Livewire puts wire:id on the first tag that starts a
     line, and an @if before the root prints a marker in front of it (the id then lands on an inner element and
     every button outside that element is dead). --}}
<div class="mx-auto flex w-full max-w-[760px] flex-col gap-[18px]">
@if ($withdrawn)
    {{-- QA9: the ketua withdrew this invitation; nothing of the team is shown any more. --}}
    <x-sth.alert tone="warning" title="Undangan dibatalkan">{{ \App\Livewire\Siswa\KonfirmasiTim::WITHDRAWN }} Kamu tidak perlu menjawabnya lagi.</x-sth.alert>
@else
    @php($bidangKey = $competition->bidang?->cssKey() ?? 'sains')
    {{-- Same head as Detail lomba: poster on the left, the invitation, the name and the key facts beside it. --}}
    <x-sth.lomba-head :bidang="$bidangKey" :seed="$competition->getKey()" :poster="\App\Support\Sampul::poster($competition)" :facts="$facts"
                      alt="Poster {{ $competition->name }}" aria-labelledby="h-undangan">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
            <span class="text-sm font-semibold text-muted">Undangan tim</span>
            @if ($competition->bidang)
                <x-sth.chip :bidang="$competition->bidang" />
            @endif
        </div>
        <h2 id="h-undangan" class="title-page m-0 text-xl @min-[460px]:text-2xl">{{ $competition->name }}</h2>
        <p class="m-0 text-sm text-muted">{{ \App\Livewire\Siswa\Lomba\LombaPresenter::penyelenggara($competition) }}</p>
        @if ($detailUrl)
            <x-sth.link :href="$detailUrl" icon-right="chevron-right" class="self-start">Lihat detail lomba</x-sth.link>
        @endif
    </x-sth.lomba-head>

    <x-sth.card as="section" aria-labelledby="h-tim" class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
            <h2 id="h-tim" class="title-section m-0">Tim</h2>
            <x-sth.chip :tone="$registration->status->tone()">{{ $registration->status->label() }}</x-sth.chip>
        </div>
        <ul class="m-0 flex list-none flex-col gap-2 p-0">
            @foreach ($members as $member)
                <li wire:key="m-{{ $member['id'] }}" class="flex items-center gap-3 rounded-tile border border-line p-2.5">
                    <x-sth.avatar :name="$member['name']" size="sm" />
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span class="flex items-center gap-2 font-semibold">
                            <span class="ellip">{{ $member['name'] }}</span>
                            @if ($member['me'])<x-sth.chip tone="blue" size="sm">Kamu</x-sth.chip>@endif
                        </span>
                        <span class="text-xs text-muted">{{ $member['role'] }} · {{ $member['kelas'] }}</span>
                    </div>
                    <x-sth.chip :tone="$member['state'][1]" size="sm">{{ $member['state'][0] }}</x-sth.chip>
                </li>
            @endforeach
        </ul>
        <p class="m-0 text-sm text-muted">Pembimbing: <span class="font-semibold text-ink">{{ $mentorName }}</span></p>
    </x-sth.card>

    @error('registration')
        <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
    @enderror

    @if ($canRespond)
        <x-sth.card as="section" tone="warn" aria-labelledby="h-jawab" class="flex flex-col gap-3">
            <h2 id="h-jawab" class="title-card m-0">Ikut tim ini?</h2>
            <p class="m-0 text-sm text-ink-2">Setelah semua anggota konfirmasi, pendaftaran diteruskan ke Kepala Sekolah. Hanya kamu yang bisa menjawab undangan ini.</p>
            {{-- D-149: the anggota checks the own No HP before joining. --}}
            <x-sth.input wire:model="phone" id="no-hp" type="tel" inputmode="tel" autocomplete="tel" label="No HP" hint="Contoh 081234567890." />
            <div class="flex flex-wrap gap-2">
                <x-sth.button icon="check" wire:click="ikut" wire:loading.attr="disabled">Ikut tim</x-sth.button>
                <x-sth.button variant="quiet" data-sth-open="tolak-undangan" aria-haspopup="dialog">Tolak</x-sth.button>
            </div>
        </x-sth.card>

        {{-- QA10 (D-109): the ketua is told only while the ketua's account can log in. --}}
        <x-sth.modal id="tolak-undangan" title="Tolak undangan?" :description="$notifiesLeader ? 'Ketua tim akan diberi tahu. Keputusan ini tidak bisa diubah.' : 'Keputusan ini tidak bisa diubah.'" size="sm">
            <p class="m-0">Kamu tidak ikut tim {{ $competition->name }}.</p>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
                <x-sth.button variant="danger" wire:click="tolak" wire:loading.attr="disabled">Tolak undangan</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @elseif ($expired)
        {{-- QA10: expired at the deadline (or about to be), not declined by the student. --}}
        <x-sth.alert tone="warning">Undangan ini sudah tidak berlaku karena batas pendaftaran lewat.</x-sth.alert>
    @elseif ($mine?->declined_at)
        <x-sth.alert>Kamu sudah menolak undangan ini.</x-sth.alert>
    @elseif ($mine?->confirmed_at)
        <x-sth.alert tone="success">Kamu sudah ikut tim ini.
            @if ($lombaSayaUrl)<x-sth.link :href="$lombaSayaUrl" icon-right="chevron-right">Pantau di Lomba saya</x-sth.link>@endif
        </x-sth.alert>
    @else
        <x-sth.alert tone="warning">Undangan ini sudah tidak berlaku.</x-sth.alert>
    @endif
@endif
</div>
