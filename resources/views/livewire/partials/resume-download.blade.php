{{--
    Back from the password page: start, once, the download that asked for it (RequiresRecentPassword,
    QA Q2-18 / backlog #63). Include it inside the root element of a screen that uses the trait:
    @include('livewire.partials.resume-download')
    $resumeDownload is set only by the server (a literal call such as "export"), never from the request.
--}}
@if ($resumeDownload)
    <span hidden wire:init="{{ $resumeDownload }}"></span>
@endif
