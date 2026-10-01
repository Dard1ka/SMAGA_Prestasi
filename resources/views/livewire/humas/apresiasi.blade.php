{{-- Apresiasi (Humas + Kesiswaan). Data is prepared in App\Livewire\Humas\Apresiasi. --}}
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
    <div class="flex min-w-0 flex-col gap-5">
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

        @if ($past !== [])
            <section aria-labelledby="apr-lewat" class="flex flex-col gap-3">
                <x-sth.page-header id="apr-lewat" title="Sudah lewat" subtitle="10 acara terakhir." />
                @foreach ($past as $event)
                    @include('livewire.humas.partials.appreciation-event', ['event' => $event])
                @endforeach
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
</div>
