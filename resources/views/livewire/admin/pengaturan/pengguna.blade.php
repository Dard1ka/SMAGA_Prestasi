{{-- Pengguna & peran (Admin-Pengaturan.dc.html tab "Pengguna"; also /admin/pengguna). Passwords are never shown back. --}}
@php
    $statusTone = fn ($status) => match ($status) {
        \App\Enums\UserStatus::Active => 'green',
        \App\Enums\UserStatus::PendingActivation => 'orange',
        \App\Enums\UserStatus::Disabled => 'red',
        default => 'gray',
    };
@endphp
<section aria-labelledby="h-pengguna" class="flex flex-col gap-5">
    <x-sth.page-header id="h-pengguna" title="Pengguna & peran" subtitle="Akun staf sekolah. Admin bisa mengganti pejabat.">
        @if ($canCreate)
            <x-slot:actions>
                <x-sth.button icon="plus" size="sm" data-sth-open="staf-baru" aria-haspopup="dialog">Tambah pengguna</x-sth.button>
            </x-slot:actions>
        @endif
    </x-sth.page-header>

    @if ($pending)
        <x-sth.alert tone="warning" title="Penggantian Kepala Sekolah dijadwalkan">
            Peran Kepala Sekolah pindah dari {{ $pending->fromUser?->name ?? '–' }} ke {{ $pending->toUser?->name }}
            pada {{ $pending->effective_at->translatedFormat('j M Y H:i') }}.
            <span class="mt-2 block">
                <x-sth.button variant="quiet" size="sm" wire:click="openCancel('{{ $pending->id }}')">Batalkan penggantian</x-sth.button>
            </span>
        </x-sth.alert>
    @endif

    <div class="max-w-md">
        <x-sth.input type="search" wire:model.live.debounce.300ms="search" label="Cari staf" hide-label icon="search" placeholder="Cari nama atau nama pengguna" />
    </div>

    @if ($staff->isEmpty())
        <x-sth.empty-state icon="users" title="Tidak ada akun yang cocok">Ganti kata pencarian.</x-sth.empty-state>
    @else
        <x-sth.table caption="Akun staf per peran, {{ $staff->count() }} akun" caption-visible>
            <x-slot:head>
                <th scope="col" class="w-44">Peran</th>
                <th scope="col">Pengguna</th>
                <th scope="col" class="w-48">Status</th>
                <th scope="col" class="relative w-72"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($staff as $member)
                @php
                    $roles = $member->getRoleNames();
                    $isPrincipal = $roles->contains(\App\Enums\RoleName::KepalaSekolah->value);
                    $isSelf = $member->id === auth()->id();
                @endphp
                <tr wire:key="staf-{{ $member->id }}">
                    <td>
                        @forelse ($roles as $roleName)
                            <span class="block font-semibold">{{ \App\Enums\RoleName::tryFrom($roleName)?->label() ?? $roleName }}</span>
                        @empty
                            <span class="block muted">Tanpa peran</span>
                        @endforelse
                        @isset($pendingRoles[$member->id])
                            <span class="block text-sm muted">Menunggu: {{ $pendingRoles[$member->id] }}</span>
                        @endisset
                    </td>
                    <td>
                        <span class="flex items-center gap-2.5">
                            <x-sth.avatar :name="$member->name" size="sm" />
                            <span class="flex min-w-0 flex-col">
                                <span class="truncate font-semibold">{{ $member->name }}</span>
                                <span class="text-sm muted">Akun: {{ $member->username ?? $member->email ?? '–' }}</span>
                            </span>
                        </span>
                    </td>
                    <td>
                        <span class="flex flex-wrap gap-1.5">
                            <x-sth.chip :tone="$statusTone($member->status)" size="sm">{{ $member->status?->label() }}</x-sth.chip>
                            @if ($member->two_factor_confirmed_at)
                                <x-sth.chip tone="blue" size="sm" icon="shield">2FA</x-sth.chip>
                            @endif
                        </span>
                    </td>
                    <td>
                        @if ($isSelf)
                            <span class="text-sm muted">Akun kamu</span>
                        @else
                            <span class="flex flex-wrap justify-end gap-2">
                                @if ($isPrincipal && $canReplace)
                                    <x-sth.button variant="soft" size="sm" wire:click="openPrincipal" :disabled="$pending !== null">Ganti pejabat</x-sth.button>
                                @elseif (! $isPrincipal && $roles->doesntContain(\App\Enums\RoleName::Siswa->value))
                                    <x-sth.button variant="quiet" size="sm" wire:click="openStaff({{ $member->id }}, 'staf-peran')">Ubah peran</x-sth.button>
                                @endif
                                <x-sth.button variant="quiet" size="sm" wire:click="openStaff({{ $member->id }}, 'staf-reset')">Reset akun</x-sth.button>
                                @if (! $isPrincipal && $member->canLogIn())
                                    <x-sth.button variant="danger" size="sm" wire:click="openStaff({{ $member->id }}, 'staf-nonaktif')">Nonaktifkan</x-sth.button>
                                @endif
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-sth.table>
    @endif

    @if ($canReplace && $principal === null && $pending === null)
        <x-sth.alert tone="warning" title="Belum ada akun Kepala Sekolah">
            Pilih akun staf yang menjadi Kepala Sekolah.
            <span class="mt-2 block"><x-sth.button variant="quiet" size="sm" wire:click="openPrincipal">Tetapkan Kepala Sekolah</x-sth.button></span>
        </x-sth.alert>
    @endif

    {{-- B5 (backlog #13): privileged role changes waiting for a second Admin or the 24 h delay. --}}
    <livewire:admin.role-change-requests :key="'peran-menunggu'" />

    {{-- Bantuan akun siswa (exact NIS/NISN only, SECURITY §3m-3) --}}
    <x-sth.card as="section" aria-labelledby="h-bantuan-siswa" class="flex flex-col gap-4">
        <div>
            <h3 id="h-bantuan-siswa" class="title-card">Bantuan akun siswa</h3>
            <p class="text-sm muted">Untuk siswa yang lupa kata sandi atau akunnya diambil alih orang lain.</p>
        </div>
        <form wire:submit="findStudent" class="flex flex-wrap items-end gap-3" novalidate>
            <x-sth.input wire:model="studentQuery" label="NIS atau NISN" inputmode="numeric" autocomplete="off" class="w-full max-w-xs" />
            <x-sth.button type="submit" variant="soft" icon="search">Cari</x-sth.button>
        </form>

        @if ($studentAccount)
            <div class="flex flex-wrap items-center gap-4 rounded-tile border border-line p-4">
                <x-sth.avatar :name="$studentAccount['name']" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $studentAccount['name'] }}</p>
                    <p class="text-sm muted">
                        Kelas {{ $studentAccount['classroom'] ?? '–' }}
                        · Email {{ $studentAccount['email'] ?? 'belum terhubung' }}
                        · Login terakhir {{ $studentAccount['lastLogin'] ?? '–' }}
                    </p>
                </div>
                <x-sth.chip :tone="$statusTone($studentAccount['status'])">{{ $studentAccount['status']?->label() }}</x-sth.chip>
                <span class="flex w-full flex-wrap justify-end gap-2">
                    <x-sth.button variant="soft" size="sm" wire:click="openStudent('siswa-reset')">Reset kata sandi & email</x-sth.button>
                    @if ($studentAccount['status']?->canLogIn())
                        <x-sth.button variant="danger" size="sm" wire:click="openStudent('siswa-nonaktif')">Nonaktifkan atau arsipkan</x-sth.button>
                    @endif
                </span>
            </div>

            @if ($studentAccount['canConsent'])
                <form wire:submit="saveConsent" class="flex flex-col gap-3 rounded-tile border border-line p-4" aria-labelledby="h-persetujuan" novalidate>
                    <div>
                        <h4 id="h-persetujuan" class="text-[15px] font-bold">Persetujuan publikasi</h4>
                        <p class="text-sm muted">Ubah sesuai formulir izin orang tua. Tanpa izin publikasi, halaman publik hanya menulis "Siswa kelas …".</p>
                    </div>
                    <x-sth.checkbox wire:model="photoConsent" label="Foto boleh dipakai di publikasi" />
                    <x-sth.checkbox wire:model="publishConsent" label="Nama dan prestasi boleh tampil di halaman publik" hint="Jika dicabut, tautan halaman publik siswa diganti." />
                    <x-sth.textarea wire:model="consentNote" label="Dasar perubahan" rows="2" hint="Contoh: formulir izin orang tua 1 Okt 2026." />
                    <span class="flex justify-end">
                        <x-sth.button type="submit" variant="soft" size="sm" wire:loading.attr="disabled" wire:target="saveConsent">Simpan persetujuan</x-sth.button>
                    </span>
                </form>
            @endif
        @endif
    </x-sth.card>

    {{-- Dialogs ----------------------------------------------------------------------------------- --}}
    <x-sth.modal id="staf-baru" title="Tambah pengguna" description="Akun staf baru. Pemilik mengganti kata sandi, menghubungkan email, dan memasang 2FA saat login pertama." variant="drawer">
        <form id="staf-baru-form" wire:submit="createStaff" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="new.name" label="Nama lengkap" autocomplete="off" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-sth.input wire:model="new.username" label="Nama pengguna" hint="Huruf kecil, contoh: dwi.rahayu" autocomplete="off" />
                <x-sth.input wire:model="new.email" type="email" label="Email" optional autocomplete="off" />
            </div>
            {{-- W4 (lead decision): privileged roles follow the "Ubah peran" approval rule. --}}
            <x-sth.select wire:model="new.role" label="Peran" :options="$roleOptions"
                          hint="Admin, Kesiswaan, dan Tata Usaha: akun dibuat tanpa peran dulu. Perannya perlu persetujuan Admin lain, atau berlaku 24 jam kemudian bila hanya ada satu Admin." />
            <div class="grid gap-4 sm:grid-cols-3">
                <x-sth.input wire:model="new.honorific" label="Sapaan" placeholder="Bu / Pak" optional autocomplete="off" />
                <x-sth.input wire:model="new.nip" label="NIP" inputmode="numeric" optional autocomplete="off" class="sm:col-span-2" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-sth.input wire:model="new.rank" label="Pangkat/golongan" optional autocomplete="off" />
                <x-sth.input wire:model="new.position" label="Jabatan" optional autocomplete="off" />
            </div>
            <x-sth.input wire:model="new.password" type="password" label="Kata sandi sementara" revealable autocomplete="new-password" hint="Berikan langsung ke pemiliknya. Tidak ditampilkan lagi." />
            <x-sth.input wire:model="new.password_confirmation" type="password" label="Ulangi kata sandi sementara" revealable autocomplete="new-password" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="staf-baru-form" wire:loading.attr="disabled" wire:target="createStaff">Buat akun</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="staf-peran" title="Ubah peran" :description="$target ? 'Akun '.$target->name.'. Pengguna perlu login ulang.' : null" size="sm">
        <form id="staf-peran-form" wire:submit="changeRole" class="flex flex-col gap-4" novalidate>
            <x-sth.select wire:model="role" label="Peran baru" :options="$roleOptions"
                          hint="Admin, Kesiswaan, dan Tata Usaha perlu persetujuan Admin lain, atau berlaku 24 jam kemudian bila hanya ada satu Admin." />
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="staf-peran-form" wire:loading.attr="disabled" wire:target="changeRole">Simpan peran</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="staf-reset" title="Reset akun staf" :description="$target ? 'Akun '.$target->name.': kata sandi sementara, email diverifikasi ulang, 2FA dipasang ulang.' : null" size="sm">
        <form id="staf-reset-form" wire:submit="resetStaff" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="tempPassword" type="password" label="Kata sandi sementara" revealable autocomplete="new-password" />
            <x-sth.input wire:model="tempPassword_confirmation" type="password" label="Ulangi kata sandi sementara" revealable autocomplete="new-password" />
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="staf-reset-form" wire:loading.attr="disabled" wire:target="resetStaff">Reset akun</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="staf-nonaktif" title="Nonaktifkan akun" :description="$target ? 'Akun '.$target->name.' tidak bisa login lagi dan semua sesinya diakhiri.' : null" size="sm">
        <form id="staf-nonaktif-form" wire:submit="disableStaff" class="flex flex-col gap-4" novalidate>
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2" hint="Contoh: pindah tugas." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" variant="danger" form="staf-nonaktif-form" wire:loading.attr="disabled" wire:target="disableStaff">Nonaktifkan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="ganti-kepsek" title="Ganti Kepala Sekolah" description="{{ $principal ? 'Berlaku 24 jam setelah disimpan. Kepala Sekolah saat ini dan semua Admin diberi tahu dan bisa membatalkan.' : 'Belum ada Kepala Sekolah, jadi langsung berlaku.' }}" size="md">
        <form id="ganti-kepsek-form" wire:submit="requestPrincipal" class="flex flex-col gap-4" novalidate>
            @if ($principalCandidates === [])
                <x-sth.alert tone="warning">Belum ada akun staf yang bisa dipilih. Tambah pengguna dulu (bukan Admin).</x-sth.alert>
            @else
                <x-sth.select wire:model="principalTarget" label="Akun Kepala Sekolah baru" placeholder="Pilih akun" :options="$principalCandidates"
                              hint="Akun Admin tidak bisa dipilih: Admin dan Kepala Sekolah akun terpisah." />
            @endif
            <x-sth.textarea wire:model="principalReason" label="Alasan" rows="2" hint="Contoh: SK pengangkatan Kepala Sekolah baru." />
            <p class="text-sm muted">Tanda tangan lama dilepas saat penggantian berlaku. Unggah tanda tangan Kepala Sekolah baru di tab Surat & tanda tangan.</p>
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="ganti-kepsek-form" wire:loading.attr="disabled" wire:target="requestPrincipal">Simpan penggantian</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="batal-kepsek" title="Batalkan penggantian?" description="Kepala Sekolah tetap memakai akun saat ini." size="sm">
        <form id="batal-kepsek-form" wire:submit="cancelReplacement" class="flex flex-col gap-4" novalidate>
            <x-sth.textarea wire:model="cancelReason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
            <x-sth.button type="submit" variant="danger" form="batal-kepsek-form" wire:loading.attr="disabled" wire:target="cancelReplacement">Batalkan penggantian</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- B1 — K10: reset issues a random temporary password (72 h), shown once in "siswa-sandi". --}}
    <x-sth.modal id="siswa-reset" title="Reset kata sandi & email?" description="Pastikan identitas siswa sudah dicek tatap muka. Sistem membuat kata sandi sementara (berlaku 72 jam), melepas email, dan mengeluarkan semua perangkat." size="sm">
        <form id="siswa-reset-form" wire:submit="resetStudent" class="flex flex-col gap-4" novalidate>
            <x-sth.textarea wire:model="studentReason" label="Alasan" rows="2" hint="Contoh: siswa datang ke TU, akun diambil alih." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="siswa-reset-form" wire:loading.attr="disabled" wire:target="resetStudent">Reset akun</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="siswa-sandi" title="Kata sandi sementara" description="Tampil sekali ini saja. Berikan langsung ke siswa, jangan difoto atau dikirim lewat chat." size="sm">
        @if ($issuedPassword)
            <div class="flex flex-col gap-3">
                <p class="m-0 rounded-tile border border-line bg-subtle px-4 py-3 text-center font-mono text-2xl font-bold tracking-[0.12em] select-all">{{ $issuedPassword }}</p>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-[13.5px] text-ink-2">
                    <li>Berlaku 72 jam. Setelah itu siswa perlu reset lagi.</li>
                    <li>Siswa masuk dengan NIS/NISN dan kata sandi ini, lalu wajib menggantinya dan menghubungkan email.</li>
                    <li>Huruf besar dan kecil dibedakan.</li>
                </ul>
            </div>
        @else
            <p class="m-0 text-[13.5px] text-muted">Kata sandi sementara sudah ditutup.</p>
        @endif
        <x-slot:footer>
            <x-sth.button wire:click="forgetIssuedPassword">Sudah saya berikan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="siswa-nonaktif" title="Nonaktifkan atau arsipkan akun siswa" description="Akun tidak bisa login lagi. Prestasinya tetap tampil di peringkat." size="sm">
        <form id="siswa-nonaktif-form" wire:submit="disableStudent" class="flex flex-col gap-4" novalidate>
            <x-sth.segmented wire:model="studentStatus" label="Jadikan" :options="['disabled' => 'Nonaktif', 'archived' => 'Alumni (arsip)']" block />
            <x-sth.textarea wire:model="studentReason" label="Alasan" rows="2" hint="Contoh: pindah sekolah." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" variant="danger" form="siswa-nonaktif-form" wire:loading.attr="disabled" wire:target="disableStudent">Simpan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</section>
