{{--
    Public footer: copyright line + link to the school site.

    <x-sth.public-footer />
--}}
<footer {{ $attributes->class('border-t border-line bg-surface') }}>
    <div class="mx-auto flex w-full max-w-[1440px] flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 text-xs text-muted md:min-h-12 md:px-14">
        <span>© {{ now()->year }} SMA Negeri 3 Semarang · SMAGA Talent Hub</span>
        <a class="lnk text-xs" href="https://sman3-smg.sch.id" rel="noopener">Situs sekolah</a>
    </div>
</footer>
