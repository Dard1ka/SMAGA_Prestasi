{{-- Publik · Prestasi seorang siswa (/prestasi/siswa/{slug}, noindex). Same partial as level 3 of Prestasi per bidang. --}}
<div class="flex flex-col gap-4">
    <x-sth.link :href="route('public.prestasi')" icon="chevron-left" class="self-start">Prestasi per bidang</x-sth.link>
    <x-sth.card as="section" aria-label="Prestasi {{ $detail['name'] }}" padding="lg" class="mx-auto w-full max-w-[960px] px-4 sm:px-6">
        @include('livewire.publik.partials.siswa-detail', ['detail' => $detail, 'level' => 1])
    </x-sth.card>
</div>
