{{-- Public letter verification (QR target). Only number, type, date and lomba name: no personal data. --}}
<x-layouts::public title="Verifikasi surat tugas" robots="noindex, nofollow">
    <div class="mx-auto flex w-full max-w-[560px] flex-col gap-5">
        <x-sth.page-header :level="1" title="Verifikasi surat tugas" subtitle="Cek keaslian surat tugas SMA Negeri 3 Semarang." />

        @if ($letter && ($revoked ?? false))
            {{-- B3 (#11): revoked by Tata Usaha; the number stays on record but is no longer valid. --}}
            <x-sth.card as="section" padding="lg" class="flex flex-col gap-4" aria-labelledby="hasil-verifikasi">
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="alert" /></span>
                    <div class="flex flex-col gap-0.5">
                        <h2 id="hasil-verifikasi" class="title-card">Dicabut</h2>
                        <p class="muted m-0 text-sm">Surat ini pernah diterbitkan, tetapi sudah dicabut dan tidak berlaku lagi.</p>
                    </div>
                </div>
                <dl class="m-0 grid gap-3 sm:grid-cols-[140px_1fr]">
                    <dt class="text-sm font-semibold text-muted">Jenis surat</dt>
                    <dd class="m-0 text-sm font-semibold">{{ $letter->type->label() }}</dd>
                    <dt class="text-sm font-semibold text-muted">Nomor</dt>
                    <dd class="num m-0 text-sm font-semibold">{{ $letter->full_number }}</dd>
                    <dt class="text-sm font-semibold text-muted">Tanggal</dt>
                    <dd class="m-0 text-sm font-semibold">{{ $letter->issued_on?->locale('id')->translatedFormat('j F Y') }}</dd>
                    <dt class="text-sm font-semibold text-muted">Dicabut</dt>
                    <dd class="m-0 text-sm font-semibold">{{ $letter->revoked_at?->locale('id')->translatedFormat('j F Y') }}</dd>
                    @if ($competitionName)
                        <dt class="text-sm font-semibold text-muted">Lomba</dt>
                        <dd class="m-0 text-sm font-semibold">{{ $competitionName }}</dd>
                    @endif
                    @if ($replacementNumber)
                        <dt class="text-sm font-semibold text-muted">Diganti dengan</dt>
                        <dd class="num m-0 text-sm font-semibold">{{ $replacementNumber }}</dd>
                    @endif
                </dl>
                <x-sth.alert tone="warning">Minta surat yang berlaku kepada pemegang surat, atau hubungi Tata Usaha SMA Negeri 3 Semarang.</x-sth.alert>
            </x-sth.card>
        @elseif ($letter)
            <x-sth.card as="section" padding="lg" class="flex flex-col gap-4" aria-labelledby="hasil-verifikasi">
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check-circle" /></span>
                    <div class="flex flex-col gap-0.5">
                        <h2 id="hasil-verifikasi" class="title-card">Sah</h2>
                        <p class="muted m-0 text-sm">Surat ini diterbitkan melalui SMAGA Talent Hub.</p>
                    </div>
                </div>
                <dl class="m-0 grid gap-3 sm:grid-cols-[140px_1fr]">
                    <dt class="text-sm font-semibold text-muted">Jenis surat</dt>
                    <dd class="m-0 text-sm font-semibold">{{ $letter->type->label() }}</dd>
                    <dt class="text-sm font-semibold text-muted">Nomor</dt>
                    <dd class="num m-0 text-sm font-semibold">{{ $letter->full_number }}</dd>
                    <dt class="text-sm font-semibold text-muted">Tanggal</dt>
                    <dd class="m-0 text-sm font-semibold">{{ $letter->issued_on?->locale('id')->translatedFormat('j F Y') }}</dd>
                    @if ($competitionName)
                        <dt class="text-sm font-semibold text-muted">Lomba</dt>
                        <dd class="m-0 text-sm font-semibold">{{ $competitionName }}</dd>
                    @endif
                </dl>
                <x-sth.alert>Pastikan nomor dan nama lomba sama dengan surat yang kamu pegang.</x-sth.alert>
            </x-sth.card>
        @else
            <x-sth.card as="section" padding="lg" class="flex flex-col gap-3" aria-labelledby="hasil-verifikasi">
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="alert" /></span>
                    <div class="flex flex-col gap-0.5">
                        <h2 id="hasil-verifikasi" class="title-card">Tidak ditemukan</h2>
                        <p class="muted m-0 text-sm">Kode verifikasi ini tidak cocok dengan surat mana pun.</p>
                    </div>
                </div>
                <p class="m-0 text-sm">Periksa kembali link atau kode QR. Jika ragu, hubungi Tata Usaha SMA Negeri 3 Semarang.</p>
            </x-sth.card>
        @endif
    </div>
</x-layouts::public>
