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

    {{-- FS1 QA round 2 (QA-DC-05): a full NISN from the top bar search is answered in "Bantuan akun siswa" at the bottom
         of the page; this note on the first screen says what was found and links there. --}}
    @if ($nisnNotice)
        @if ($nisnNotice['name'] !== null)
            <x-sth.alert :title="'Hasil NISN '.$nisnNotice['nisn']">
                Akun siswa {{ $nisnNotice['name'] }} ada di Bantuan akun siswa.
                <span class="mt-1 block"><x-sth.link href="#h-bantuan-siswa" :navigate="false" icon-right="chevron-down">Lihat akun siswa</x-sth.link></span>
            </x-sth.alert>
        @else
            <x-sth.alert tone="warning" :title="'Hasil NISN '.$nisnNotice['nisn']">Tidak ada siswa dengan NISN {{ $nisnNotice['nisn'] }}.</x-sth.alert>
        @endif
    @endif

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
        {{-- FS1 QA: the top bar search ("Cari nama atau NISN") lands here, and a name only finds staff; a student is found by
             the full NISN in "Bantuan akun siswa" (SECURITY §3m-3), so the empty list says where to look. --}}
        <x-sth.empty-state icon="users" title="Tidak ada akun yang cocok">Ganti kata pencarian. Kotak ini hanya mencari akun staf; akun siswa dicari dengan nama atau NISN di Bantuan akun siswa di bawah.</x-sth.empty-state>
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
                            @if ($twoFactor && $member->two_factor_confirmed_at)
                                <x-sth.chip tone="blue" size="sm" icon="shield">2FA</x-sth.chip>
                            @endif
                        </span>
                    </td>
                    <td>
                        @if ($isSelf)
                            <span class="text-sm muted">Akun sendiri</span>
                        @else
                            <span class="flex flex-wrap justify-end gap-2">
                                @if ($isPrincipal && $canReplace)
                                    <x-sth.button variant="soft" size="sm" wire:click="openPrincipal" :disabled="$pending !== null">Ganti pejabat</x-sth.button>
                                @elseif (! $isPrincipal && $roles->doesntContain(\App\Enums\RoleName::Siswa->value))
                                    <x-sth.button variant="quiet" size="sm" wire:click="openStaff({{ $member->id }}, 'staf-peran')">Ubah peran</x-sth.button>
                                @endif
                                {{-- QA9: an account that cannot log in is not reset; the row says why. --}}
                                @isset($resetRefusals[$member->id])
                                    <span class="self-center text-sm muted">{{ $resetRefusals[$member->id] }}</span>
                                @else
                                    <x-sth.button variant="quiet" size="sm" wire:click="openStaff({{ $member->id }}, 'staf-reset')">Reset akun</x-sth.button>
                                @endisset
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

    {{-- B5 (backlog #13): role requests made before 21 Okt 2026 that still wait (new changes apply at once). --}}
    <livewire:admin.role-change-requests :key="'peran-menunggu'" />

    {{-- Bantuan akun siswa: a full NISN (exact, SECURITY §3m-3) or a part of the name (D-158) --}}
    <x-sth.card as="section" aria-labelledby="h-bantuan-siswa" class="flex flex-col gap-4">
        <div>
            <h3 id="h-bantuan-siswa" class="title-card">Bantuan akun siswa</h3>
            <p class="text-sm muted">Untuk akun siswa yang diambil alih orang lain. Siswa yang hanya lupa kata sandi memakai "Lupa kata sandi" di halaman login.</p>
        </div>
        <form wire:submit="findStudent" class="flex flex-wrap items-end gap-3" novalidate>
            <x-sth.input wire:model="studentQuery" label="Nama atau NISN" autocomplete="off" class="w-full max-w-sm" />
            <x-sth.button type="submit" variant="soft" icon="search">Cari</x-sth.button>
        </form>
        <p class="-mt-2 mb-0 text-xs text-muted">Nama minimal 3 huruf, atau NISN 10 digit.</p>

        @if ($studentMatches !== [])
            <div class="flex flex-col gap-2">
                <p class="m-0 text-sm muted">{{ count($studentMatches) }} siswa cocok. Pilih salah satu.</p>
                <ul class="m-0 flex list-none flex-col gap-2 p-0" aria-label="Siswa yang cocok">
                    @foreach ($studentMatches as $match)
                        @php($matchStatus = \App\Enums\UserStatus::from($match['status']))
                        <li wire:key="cocok-{{ $match['id'] }}" class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                            <div class="min-w-0 flex-1 [overflow-wrap:anywhere]">
                                <p class="m-0 font-semibold">{{ $match['name'] }}</p>
                                <p class="m-0 text-sm muted">Kelas {{ $match['classroom'] ?? '–' }} · NISN <span class="num">{{ $match['nisn'] ?? '–' }}</span></p>
                            </div>
                            <x-sth.chip :tone="$statusTone($matchStatus)" size="sm">{{ $matchStatus->label() }}</x-sth.chip>
                            <x-sth.button variant="soft" size="sm" wire:click="chooseStudent({{ $match['id'] }})">Pilih</x-sth.button>
                        </li>
                    @endforeach
                </ul>
                @if ($studentMatchesMore)
                    <p class="m-0 text-sm muted">Lebih dari {{ count($studentMatches) }} siswa cocok. Ketik nama lebih lengkap.</p>
                @endif
            </div>
        @endif

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
                    {{-- QA9: a disabled or archived account cannot log in, so the reset is not offered; the card says why. --}}
                    @if ($studentAccount['resetRefusal'])
                        <span class="text-sm muted">{{ $studentAccount['resetRefusal'] }}</span>
                    @else
                        <x-sth.button variant="soft" size="sm" wire:click="openStudent('siswa-reset')">Reset kata sandi &amp; email</x-sth.button>
                    @endif
                    @if ($studentAccount['status']?->canLogIn())
                        <x-sth.button variant="danger" size="sm" wire:click="openStudent('siswa-nonaktif')">Nonaktifkan atau arsipkan</x-sth.button>
                    @endif
                </span>
            </div>
        @endif
    </x-sth.card>

    {{-- Dialogs ----------------------------------------------------------------------------------- --}}
    <x-sth.modal id="staf-baru" title="Tambah pengguna" :description="$twoFactor ? 'Akun staf baru. Saat login pertama pemilik menghubungkan email, lalu wajib mengganti kata sandi sementara dan memasang 2FA.' : 'Akun staf baru. Saat login pertama pemilik menghubungkan email, lalu wajib mengganti kata sandi sementara.'" variant="drawer">
        <form id="staf-baru-form" wire:submit="createStaff" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="new.name" label="Nama lengkap" autocomplete="off" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.input wire:model="new.username" label="Nama pengguna" hint="Huruf kecil, contoh: dwi.rahayu" autocomplete="off" />
                <x-sth.input wire:model="new.email" type="email" label="Email" optional autocomplete="off" />
            </div>
            {{-- 21 Okt 2026 (the lead): every role applies at once, no second Admin and no 24 h wait. --}}
            <x-sth.select wire:model="new.role" label="Peran" :options="$roleOptions" hint="Peran langsung berlaku." />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-sth.input wire:model="new.honorific" label="Sapaan" placeholder="Bu / Pak" optional autocomplete="off" />
                <x-sth.input wire:model="new.nip" label="NIP" inputmode="numeric" optional autocomplete="off" class="sm:col-span-2" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <x-sth.select wire:model="role" label="Peran baru" :options="$roleOptions" hint="Langsung berlaku setelah disimpan." />
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="staf-peran-form" wire:loading.attr="disabled" wire:target="changeRole">Simpan peran</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="staf-reset" title="Reset akun staf" :description="$target ? 'Akun '.$target->name.($twoFactor ? ': kata sandi sementara, email diverifikasi ulang, 2FA dipasang ulang.' : ': kata sandi sementara, email diverifikasi ulang.') : null" size="sm">
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
            <p class="text-sm muted">Tanda tangan lama dilepas saat penggantian berlaku. Upload tanda tangan Kepala Sekolah baru di tab Surat & tanda tangan.</p>
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

    {{-- B1 — K10, lead 2026-10-21: for a taken-over account. The Admin types the temporary password (min. 8, 72 h), the
         email is removed and the student activates again; Data diri and talents stay. --}}
    <x-sth.modal id="siswa-reset" title="Reset kata sandi & email?" description="Pastikan identitas siswa sudah dicek tatap muka. Semua perangkat yang memakai akun ini dikeluarkan." size="sm">
        <form id="siswa-reset-form" wire:submit="resetStudent" class="flex flex-col gap-4" novalidate>
            <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-sm text-ink-2">
                <li>Email yang terhubung dilepas.</li>
                <li>Dalam 72 jam siswa masuk dengan NISN dan kata sandi sementara, lalu menghubungkan emailnya sendiri, memasukkan kode, dan membuat kata sandi baru.</li>
                <li>Data diri dan pilihan talenta siswa tetap tersimpan.</li>
            </ul>
            <x-sth.input wire:model="studentPassword" type="password" label="Kata sandi sementara" revealable autocomplete="new-password"
                         hint="Minimal 8 karakter. Siswa wajib menggantinya saat login." />
            <x-sth.textarea wire:model="studentReason" label="Alasan" rows="2" hint="Contoh: siswa datang ke TU, akun diambil alih." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="siswa-reset-form" wire:loading.attr="disabled" wire:target="resetStudent">Reset kata sandi &amp; email</x-sth.button>
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
