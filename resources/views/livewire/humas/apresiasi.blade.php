{{-- Apresiasi (Humas + Kesiswaan). Data is prepared in App\Livewire\Humas\Apresiasi. --}}
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
    <div class="flex min-w-0 flex-col gap-5">
        @if ($overdue !== [])
            <section aria-labelledby="apr-konfirmasi" class="flex flex-col gap-3">
                <x-sth.page-header id="apr-konfirmasi" title="Perlu dikonfirmasi" subtitle="Tanggalnya sudah lewat. Tandai sudah dilaksanakan, atau hapus kalau batal." />
                @foreach ($overdue as $event)
                    @include('livewire.humas.partials.appreciation-event', ['event' => $event])
                @endforeach
            </section>
        @endif

        <section aria-labelledby="apr-mendatang" class="flex flex-col gap-3">
            <x-sth.page-header id="apr-mendatang" title="Acara mendatang" />
            @forelse ($upcoming as $event)
                @include('livewire.humas.partials.appreciation-event', ['event' => $event])
            @empty
                <x-sth.empty-state icon="award" title="Belum ada acara apresiasi" :level="3">
                    Buat acara, misalnya upacara Senin, lalu masukkan prestasi siswa.
                </x-sth.empty-state>
            @endforelse
        </section>

        @if ($done !== [])
            <section aria-labelledby="apr-selesai" class="flex flex-col gap-3">
                <x-sth.page-header id="apr-selesai" title="Sudah dilaksanakan" subtitle="{{ $doneCount }} acara, terbaru di atas." />
                @foreach ($done as $event)
                    @include('livewire.humas.partials.appreciation-event', ['event' => $event])
                @endforeach
                @if ($doneMore)
                    <div class="flex justify-center">
                        <x-sth.button variant="quiet" size="sm" wire:click="showMoreDone">Tampilkan lebih banyak</x-sth.button>
                    </div>
                @endif
            </section>
        @endif
    </div>

    @if ($canCreate)
        <x-sth.card as="section" aria-labelledby="apr-baru" class="flex flex-col gap-3">
            <h2 id="apr-baru" class="title-card m-0">Buat acara apresiasi</h2>
            <form wire:submit="createEvent" class="flex flex-col gap-3" novalidate>
                <x-sth.input wire:model="name" label="Nama acara" placeholder="Upacara Senin, 5 Oktober 2026" maxlength="200" />
                <x-sth.input type="date" wire:model="heldOn" label="Tanggal" :min="$minDate" class="num" />
                <x-sth.button type="submit" icon="plus" wire:loading.attr="disabled" wire:target="createEvent">Buat acara</x-sth.button>
            </form>
        </x-sth.card>
    @endif

    {{-- One confirmation dialog for "Tandai sudah dilaksanakan", "Hapus acara" and "Lepas dari acara" (opened by confirmComplete / confirmDelete / confirmRelease). --}}
    <x-sth.modal id="apresiasi-konfirmasi" size="sm" :title="$confirm['title'] ?? 'Tandai sudah dilaksanakan?'">
        @if ($confirm)
            <div class="flex flex-col gap-3">
                <div class="rounded-tile bg-subtle px-3 py-2.5">
                    @if ($confirm['row'])
                        <p class="m-0 font-semibold">{{ $confirm['row'] }}</p>
                        <p class="m-0 text-[13px] text-muted">{{ $confirm['name'] }} · {{ $confirm['date'] }}</p>
                    @else
                        <p class="m-0 font-semibold">{{ $confirm['name'] }}</p>
                        <p class="m-0 text-[13px] text-muted">{{ $confirm['date'] }} · <span class="num">{{ $confirm['count'] }}</span> prestasi</p>
                    @endif
                </div>
                <p class="m-0 text-[14.5px]">{{ $confirm['text'] }}</p>
            </div>
        @else
            <p class="m-0 text-[14.5px] text-muted">Acara tidak ditemukan. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($confirm && $confirm['delete'])
                <x-sth.button variant="danger" icon="trash" wire:click="runConfirmed" wire:loading.attr="disabled" wire:target="runConfirmed">Hapus acara</x-sth.button>
            @elseif ($confirm && $confirm['action'] === 'lepas')
                <x-sth.button variant="danger" wire:click="runConfirmed" wire:loading.attr="disabled" wire:target="runConfirmed">Lepas dari acara</x-sth.button>
            @elseif ($confirm)
                <x-sth.button icon="check" wire:click="runConfirmed" wire:loading.attr="disabled" wire:target="runConfirmed">Tandai sudah dilaksanakan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
