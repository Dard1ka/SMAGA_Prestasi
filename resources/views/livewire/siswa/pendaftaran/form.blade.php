{{-- "Pendaftaran lomba" panel (board Detail-Lomba, right column). State + rules: App\Livewire\Siswa\Pendaftaran\Form. --}}
<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-3">
        <h2 id="h-daftar" class="title-section m-0">Pendaftaran lomba</h2>
        @if ($closesShort)
            <x-sth.chip tone="gray" class="num">Batas {{ $closesShort }}</x-sth.chip>
        @endif
    </div>

    @error('competition')
        <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
    @enderror
    @error('registration')
        <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
    @enderror

    @if ($status)
        {{-- Already in a running registration of this lomba (or just sent). --}}
        <div role="status" class="flex flex-col gap-4">
            @if ($status['pendingInvite'])
                <div class="flex flex-col gap-2 rounded-tile bg-primary-tint p-4">
                    <p class="m-0 font-bold">{{ $status['leaderName'] }} mengajak kamu ikut tim.</p>
                    <p class="m-0 text-sm text-ink-2">{{ $status['answerNote'] }}</p>
                    <x-sth.button size="sm" :href="$status['undangan']" class="self-start">Lihat undangan</x-sth.button>
                </div>
            @else
                <div class="flex flex-col items-start gap-2">
                    <span class="inline-flex size-12 items-center justify-center rounded-full bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check" :size="26" /></span>
                    <h3 class="title-card m-0">{{ $status['isLeader'] ? ($status['phase'] > 2 ? 'Pendaftaran disetujui' : 'Pendaftaran terkirim') : 'Kamu sudah terdaftar' }}</h3>
                    <x-sth.chip :tone="$status['tone']">{{ $status['label'] }}</x-sth.chip>
                    @if ($status['waitingText'])
                        <p class="m-0 text-sm text-ink-2">{{ $status['waitingText'] }}</p>
                    @endif
                </div>
            @endif

            {{-- D-148: a registration for the own lomba that Kesiswaan has not verified yet stands before the approval:
                 its first step is the verification, and the later steps are not current yet (`$status['first']` = the
                 number of the first step after it). --}}
            <ol class="m-0 flex list-none flex-col gap-3 p-0">
                @if ($status['waitingLomba'])
                    <li class="flex items-start gap-3">
                        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-bold text-primary"><span class="num">1</span></span>
                        <span class="font-semibold">Kesiswaan memverifikasi lomba</span>
                    </li>
                @endif
                @if ($status['isTeam'])
                    <li class="flex items-start gap-3">
                        <span @class(['inline-flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold', 'bg-green-bg text-green' => ! $status['waitingMembers'], 'bg-primary-tint text-primary' => $status['waitingMembers'] && ! $status['waitingLomba'], 'bg-subtle text-muted' => $status['waitingLomba']])>
                            @if ($status['waitingMembers'])<span class="num">{{ $status['first'] }}</span>@else<x-sth.icon name="check" :size="16" />@endif
                        </span>
                        <div class="flex flex-col">
                            <span class="font-semibold">Anggota konfirmasi</span>
                            @if ($status['membersText'])<span class="num text-xs text-muted">{{ $status['membersText'] }}</span>@endif
                        </div>
                    </li>
                @endif
                <li class="flex items-start gap-3">
                    <span @class(['inline-flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold', 'bg-green-bg text-green' => $status['phase'] > 2, 'bg-primary-tint text-primary' => $status['phase'] <= 2 && ! $status['waitingLomba'], 'bg-subtle text-muted' => $status['waitingLomba']])>
                        @if ($status['phase'] > 2)<x-sth.icon name="check" :size="16" />@else<span class="num">{{ $status['first'] + ($status['isTeam'] ? 1 : 0) }}</span>@endif
                    </span>
                    <span class="font-semibold">Sekolah menyetujui</span>
                </li>
                <li class="flex items-start gap-3">
                    <span @class(['inline-flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold', 'bg-green-bg text-green' => $status['lettersDone'], 'bg-subtle text-muted' => ! $status['lettersDone']])>
                        @if ($status['lettersDone'])<x-sth.icon name="check" :size="16" />@else<span class="num">{{ $status['first'] + ($status['isTeam'] ? 2 : 1) }}</span>@endif
                    </span>
                    <span class="font-semibold">Tata Usaha membuat surat tugas</span>
                </li>
            </ol>

            {{-- FS1 QA: once the lomba runs, the steps above are done; this says what comes now (Laporkan hasil for the ketua). --}}
            @if ($status['stage'])
                <div class="flex flex-col items-start gap-2 rounded-tile bg-primary-tint p-4">
                    <p class="m-0 font-bold">{{ $status['stage']['title'] }}</p>
                    <p class="m-0 text-sm text-ink-2">{{ $status['stage']['text'] }}</p>
                    @if ($status['stage']['action'])
                        <x-sth.button size="sm" icon="upload" :href="$status['stage']['action']['href']">{{ $status['stage']['action']['label'] }}</x-sth.button>
                    @endif
                </div>
            @endif

            @if ($status['canInvite'])
                {{-- B3 (#9): ketua sees the team and invites a replacement while members still confirm. --}}
                <div class="flex flex-col gap-2 border-t border-line pt-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="label" id="lbl-tim-kamu">Anggota tim</span>
                        @if ($status['slotsLeft'] !== null)
                            <span class="num text-xs text-muted">sisa {{ $status['slotsLeft'] }} tempat</span>
                        @endif
                    </div>
                    <ul aria-labelledby="lbl-tim-kamu" class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($status['team'] as $row)
                            <li wire:key="tim-{{ $row['id'] }}" class="flex items-center gap-3 rounded-tile border border-line p-2.5">
                                <x-sth.avatar :name="$row['name']" size="sm" />
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="ellip font-semibold">{{ $row['name'] }}</span>
                                    <span class="text-xs text-muted">{{ $row['meta'] }}</span>
                                </div>
                                @if ($row['state'] === 'confirmed')
                                    <x-sth.chip tone="green" size="sm" icon="check">Konfirmasi</x-sth.chip>
                                @elseif ($row['state'] === 'expired')
                                    {{-- QA10: expired at the deadline, the student never answered. --}}
                                    <x-sth.chip tone="gray" size="sm">Tidak menjawab</x-sth.chip>
                                @elseif ($row['state'] === 'declined')
                                    <x-sth.chip tone="red" size="sm">Menolak</x-sth.chip>
                                @else
                                    <x-sth.chip size="sm" icon="clock">Menunggu</x-sth.chip>
                                    {{-- QA6 (#66): an unanswered invitation no longer holds the place until the deadline.
                                         D-128: the × opens the dialog below; nothing is withdrawn before the answer there. --}}
                                    <x-sth.icon-button icon="x" size="sm" :label="'Batalkan undangan '.$row['name']" aria-haspopup="dialog"
                                        wire:click="askBatalkanUndangan('{{ $row['studentId'] }}')" wire:loading.attr="disabled" wire:target="askBatalkanUndangan" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @error('members')
                        <p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>
                    @enderror

                    @if ($status['slotsLeft'] === 0)
                        <p class="m-0 text-xs text-muted">Tim sudah penuh.</p>
                    @else
                        <x-sth.input wire:model.live.debounce.500ms="q" id="undang-anggota" type="search" label="Undang anggota pengganti" icon="search"
                                     placeholder="Cari nama atau NISN" autocomplete="off" maxlength="60"
                                     :hint="$status['inviteHint']" />
                        @if ($hasil !== [])
                            <ul aria-label="Akun siswa yang cocok" class="m-0 flex list-none flex-col gap-1.5 p-0">
                                @foreach ($hasil as $row)
                                    <li wire:key="undang-{{ $row['id'] }}" class="flex items-center gap-3 rounded-tile p-2 hover:bg-hover">
                                        <x-sth.avatar :name="$row['name']" size="sm" />
                                        <div class="flex min-w-0 flex-1 flex-col">
                                            <span class="ellip font-semibold">{{ $row['name'] }}</span>
                                            <span class="text-xs text-muted">{{ $row['kelas'] }}{{ ($row['pending'] ?? false) ? ' · belum aktivasi akun' : '' }}</span>
                                        </div>
                                        @if ($row['declined'] ?? false)
                                            <x-sth.chip tone="gray" size="sm">Sudah menolak</x-sth.chip>
                                        @elseif ($row['busy'])
                                            <x-sth.chip tone="gray" size="sm">Sudah terdaftar</x-sth.chip>
                                        @else
                                            <x-sth.button variant="soft" size="sm" icon="send" wire:click="undang('{{ $row['id'] }}')" wire:loading.attr="disabled" wire:target="undang" :aria-label="'Undang '.$row['name']">Undang</x-sth.button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($searchNote)
                            <p class="m-0 text-xs text-muted" role="status">{{ $searchNote }}</p>
                        @endif
                    @endif
                </div>

                {{-- D-128: "Batalkan undangan" asks first. The invitee is told, and a team whose other members all
                     confirmed goes on to approval (Kepala Sekolah or Tata Usaha) with this step (no more members after that). --}}
                <x-sth.modal :id="\App\Livewire\Siswa\Pendaftaran\Form::WITHDRAW_DIALOG" title="Batalkan undangan?" :description="$withdrawing['notice'] ?? 'Siswa yang diundang diberi tahu.'" size="sm">
                    @if ($withdrawing)
                        <p class="m-0 [overflow-wrap:anywhere]">Undangan untuk {{ $withdrawing['name'] }} dibatalkan. {{ $withdrawing['ready'] ? 'Anggota lain sudah konfirmasi, jadi pendaftaran langsung diteruskan untuk persetujuan dan anggota tidak bisa ditambah lagi.' : 'Kamu bisa mengundangnya lagi selama tim masih menunggu konfirmasi.' }}</p>
                    @else
                        <p class="m-0 text-muted">Tidak ada undangan yang dipilih. Tutup lalu coba lagi.</p>
                    @endif
                    <x-slot:footer>
                        <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
                        @if ($withdrawing)
                            <x-sth.button variant="danger" wire:click="batalkanUndangan" wire:loading.attr="disabled" wire:target="batalkanUndangan">Batalkan undangan</x-sth.button>
                        @endif
                    </x-slot:footer>
                </x-sth.modal>
            @endif

            <p class="m-0 text-sm text-muted">Pembimbing: <span class="font-semibold text-ink">{{ $status['mentor'] }}</span></p>

            <div class="flex flex-wrap gap-2">
                @if ($status['lombaSaya'])
                    <x-sth.button variant="soft" :href="$status['lombaSaya']" icon-right="chevron-right">Lihat di Lomba saya</x-sth.button>
                @endif
                @if ($status['canCancel'])
                    <x-sth.button variant="quiet" size="sm" data-sth-open="batal-daftar" aria-haspopup="dialog">Batalkan pendaftaran</x-sth.button>
                @endif
            </div>

            @if ($status['canCancel'])
                <x-sth.modal id="batal-daftar" title="Batalkan pendaftaran?" :description="$status['cancelNote']" size="sm">
                    <p class="m-0">Pendaftaran {{ $competition->name }} akan dibatalkan.</p>
                    <x-slot:footer>
                        <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
                        <x-sth.button variant="danger" wire:click="batalkan" wire:loading.attr="disabled">Batalkan pendaftaran</x-sth.button>
                    </x-slot:footer>
                </x-sth.modal>
            @endif
        </div>
    @elseif (! $canCreate)
        <div class="flex flex-col gap-2">
            @if ($window['key'] === 'segera')
                <x-sth.alert title="Belum dibuka">{{ $window['note'] }}.</x-sth.alert>
            @elseif ($window['key'] === 'menunggu')
                {{-- QA10: a proposal Kesiswaan has not checked yet is not "ditutup" by itself; D-148: for its submitter the dates decide (Form::waitingNotice()). --}}
                <x-sth.alert :tone="$waitingNotice['tone']" :title="$waitingNotice['title']">{{ $waitingNotice['text'] }}</x-sth.alert>
            @elseif ($window['key'] === 'ditolak')
                <x-sth.alert tone="warning" title="Tidak dibuka">Usulan lomba ini ditolak {{ $rejectedBy }}, jadi tidak menerima pendaftaran.</x-sth.alert>
            @elseif ($window['key'] === 'ditutup' || ! $competition->isPublished())
                <x-sth.alert tone="warning" title="Pendaftaran ditutup">Lomba ini tidak menerima pendaftaran lagi.</x-sth.alert>
            @else
                <x-sth.alert tone="warning">Kamu belum bisa mendaftar lomba ini. Hubungi Kesiswaan bila ada kendala.</x-sth.alert>
            @endif
        </div>
    @else
        <form wire:submit="kirim" class="flex flex-col gap-4" novalidate>
            {{-- D-149: the student's own data from the account (read-only) and the No HP to reach the student. --}}
            <section aria-labelledby="h-peserta" class="flex flex-col gap-3 rounded-tile border border-line p-4">
                <h3 id="h-peserta" class="title-card m-0">Data peserta</h3>
                <dl class="m-0 grid grid-cols-[104px_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                    @foreach ($peserta as $row)
                        <dt class="text-muted">{{ $row['label'] }}</dt>
                        <dd class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $row['value'] }}</dd>
                    @endforeach
                </dl>
                <p class="m-0 text-xs text-muted">Data salah? Hubungi Kesiswaan.</p>
                <x-sth.input wire:model="phone" id="no-hp" type="tel" inputmode="tel" autocomplete="tel" label="No HP" hint="Contoh 081234567890." />
            </section>

            <div class="flex flex-col gap-2">
                <x-sth.segmented wire:model.live="type" label="Jenis peserta" block :options="[
                    'individu' => ['label' => 'Individu', 'icon' => 'user', 'disabled' => ! $individuAllowed],
                    'tim' => ['label' => 'Tim', 'icon' => 'users', 'disabled' => ! $teamAllowed],
                ]" />
                @if (! $individuAllowed || ! $teamAllowed)
                    <p class="m-0 flex items-center gap-1.5 text-xs text-muted"><x-sth.icon name="info" :size="16" />Lomba ini khusus {{ $teamAllowed ? 'tim' : 'individu' }}.</p>
                @endif
            </div>

            @if ($type === 'tim')
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="label" id="lbl-anggota">Anggota tim @if ($maxTeam)(maks {{ $maxTeam }})@endif</span>
                        <span class="num text-xs text-muted">{{ count($memberRows) + 1 }} orang</span>
                    </div>
                    <ul aria-labelledby="lbl-anggota" class="m-0 flex list-none flex-col gap-2 p-0">
                        <li class="flex items-center gap-3 rounded-tile bg-page p-2.5">
                            <x-sth.avatar :name="auth()->user()->name" size="sm" />
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="flex items-center gap-2 font-semibold"><span class="ellip">{{ auth()->user()->name }}</span><x-sth.chip tone="blue" size="sm">Kamu</x-sth.chip></span>
                                <span class="text-xs text-muted">Ketua · {{ $me?->classroom?->name }}</span>
                            </div>
                        </li>
                        @foreach ($memberRows as $row)
                            <li wire:key="anggota-{{ $row['id'] }}" class="flex items-center gap-3 rounded-tile border border-line p-2.5">
                                <x-sth.avatar :name="$row['name']" size="sm" />
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="ellip font-semibold">{{ $row['name'] }}</span>
                                    {{-- D-148: for the own queued lomba the invitation waits for Kesiswaan, not for "Kirim". --}}
                                    <span class="text-xs text-muted">{{ $row['kelas'] }} · {{ $lombaWaits ? 'diundang setelah Kesiswaan memverifikasi lomba' : 'diundang setelah kamu kirim' }}</span>
                                </div>
                                <x-sth.icon-button icon="x" size="sm" ghost :label="'Hapus '.$row['name']" wire:click="hapus('{{ $row['id'] }}')" />
                            </li>
                        @endforeach
                    </ul>
                    @error('members')
                        <p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>
                    @enderror
                </div>

                @unless ($teamFull)
                    <div class="flex flex-col gap-2">
                        <x-sth.input wire:model.live.debounce.500ms="q" id="cari-anggota" type="search" label="Tambah anggota" icon="search"
                                     placeholder="Cari nama atau NISN" autocomplete="off" maxlength="60"
                                     hint="Nama diambil dari akun masing-masing siswa." />
                        @if ($hasil !== [])
                            <ul aria-label="Akun siswa yang cocok" class="m-0 flex list-none flex-col gap-1.5 p-0">
                                @foreach ($hasil as $row)
                                    <li wire:key="hasil-{{ $row['id'] }}" class="flex items-center gap-3 rounded-tile p-2 hover:bg-hover">
                                        <x-sth.avatar :name="$row['name']" size="sm" />
                                        <div class="flex min-w-0 flex-1 flex-col">
                                            <span class="ellip font-semibold">{{ $row['name'] }}</span>
                                            <span class="text-xs text-muted">{{ $row['kelas'] }}{{ ($row['pending'] ?? false) ? ' · belum aktivasi akun' : '' }}</span>
                                        </div>
                                        @if ($row['busy'])
                                            <x-sth.chip tone="gray" size="sm">Sudah terdaftar</x-sth.chip>
                                        @elseif (in_array($row['id'], $members, true))
                                            <x-sth.chip tone="blue" size="sm">Ditambahkan</x-sth.chip>
                                        @else
                                            <x-sth.button variant="soft" size="sm" icon="plus" wire:click="tambah('{{ $row['id'] }}')" :aria-label="'Tambah '.$row['name']">Tambah</x-sth.button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($searchNote)
                            <p class="m-0 text-xs text-muted" role="status">{{ $searchNote }}</p>
                        @endif
                    </div>
                @else
                    <p class="m-0 text-xs font-semibold text-orange">Tim sudah {{ $maxTeam }} orang. Hapus anggota untuk mengganti.</p>
                @endunless
            @endif

            <x-sth.select wire:model="mentorId" id="guru" label="Guru pembimbing" placeholder="Pilih guru pembimbing" :options="$mentors" />

            {{-- QA-04: the button is never disabled by the form state. A click right after the tick used to land on a
                 button still disabled until the tick's round trip came back; now the click carries the tick, and
                 kirim() answers a missing tick or an empty team under its field. --}}
            <div class="flex flex-col gap-3 border-t border-line pt-4">
                <x-sth.checkbox wire:model.live="setuju" id="setuju">Data yang saya isi benar.</x-sth.checkbox>
                <x-sth.button type="submit" icon="send" block wire:loading.attr="disabled" wire:target="kirim"
                              aria-describedby="kirim-help">Kirim pendaftaran</x-sth.button>
                <p id="kirim-help" class="m-0 text-xs text-muted">
                    @if ($type === 'tim' && $memberRows === [])
                        Tambahkan minimal 1 anggota tim.
                    @elseif (! $setuju)
                        Centang pernyataan di atas untuk mengirim.
                    @elseif ($lombaWaits)
                        {{ \App\Livewire\Siswa\Lomba\LombaPresenter::WAITING_FOR_LOMBA }}
                    @elseif ($type === 'tim')
                        Anggota konfirmasi dulu, lalu sekolah menyetujui.
                    @else
                        Pendaftaran diteruskan untuk persetujuan.
                    @endif
                </p>
            </div>
        </form>
    @endif
</div>
