{{-- Lomba saya: Tim (members who did not decline, confirmation state) + guru pembimbing. --}}
<x-sth.card as="section" aria-labelledby="tim-judul" class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="tim-judul" class="title-card">{{ $d['isTeam'] ? 'Tim' : 'Peserta' }}</h3>
        <span class="muted text-sm"><span class="num">{{ count($d['members']) }}</span> {{ $d['isTeam'] ? 'anggota' : 'siswa' }}</span>
    </div>
    <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
        @foreach ($d['members'] as $member)
            <li class="flex items-center gap-3">
                <x-sth.avatar :name="$member['name']" size="sm" />
                <div class="flex min-w-0 flex-1 flex-col items-start gap-1">
                    <span class="flex min-w-0 flex-col">
                        <span class="text-sm font-bold">{{ $member['name'] }}@if ($member['me'])<span class="muted font-semibold"> (kamu)</span>@endif</span>
                        <span class="muted text-xs">{{ $member['meta'] }}</span>
                    </span>
                    {{-- State from Index::memberState(). --}}
                    @if ($d['isTeam'])
                        @switch($member['state'])
                            @case('confirmed')
                                <x-sth.chip tone="green" icon="check" size="sm">Sudah konfirmasi</x-sth.chip>
                                @break
                            @case('later')
                                {{-- D-148: the invitation goes out once Kesiswaan verified the lomba (FS1 QA round 2). --}}
                                <x-sth.chip icon="clock" size="sm" class="chip-wrap">Diundang setelah lomba diverifikasi</x-sth.chip>
                                @break
                            @case('not_joined')
                                {{-- SV1: the registration ended before this invitee answered; nobody waits any more. --}}
                                <x-sth.chip tone="gray" size="sm">Tidak ikut</x-sth.chip>
                                @break
                            @case('not_invited')
                                {{-- SV1: the registration ended while its lomba waited for Kesiswaan (D-148): never invited. --}}
                                <x-sth.chip tone="gray" size="sm">Tidak diundang</x-sth.chip>
                                @break
                            @default
                                <x-sth.chip icon="clock" size="sm">Menunggu konfirmasi</x-sth.chip>
                        @endswitch
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
    @if ($d['inviteHref'] ?? null)
        <x-sth.button variant="soft" size="sm" icon="plus" :href="$d['inviteHref']" class="self-start">Undang anggota pengganti</x-sth.button>
    @endif
    @if ($d['mentor'])
        <div class="flex flex-col gap-2 border-t border-line pt-3">
            <span class="label">Guru pembimbing</span>
            <div class="flex items-center gap-3">
                <x-sth.avatar :name="$d['mentor']" size="sm" />
                <span class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                    <span class="text-sm font-bold">{{ $d['mentor'] }}</span>
                    {{-- D-132: said only when the approver replaced the guru the student chose; D-150 names the role. --}}
                    @if ($d['mentorByPrincipal'] ?? false)
                        <span class="muted text-xs">Ditetapkan {{ $d['approverRole'] }}</span>
                    @endif
                </span>
            </div>
        </div>
    @endif
</x-sth.card>
