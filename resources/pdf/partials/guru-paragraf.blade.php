{{--
    SV8, D-194: one justified paragraph of the guru letter, printed line by line as App\Domain\Letters\JustifiedLines set
    it (the school's Word rule): each line its own block that never wraps again, the width it lacks or has too much
    spread over its spaces (word-spacing, a number this class computed), the last line with plain spaces.
    One line per row on purpose: whitespace between the pieces would print as a space.
    Vars: $lines (JustifiedLines::split())
--}}
@foreach ($lines as $line)
    <div class="baris"@if ($line['spacing'] != 0) style="word-spacing: {{ sprintf('%.3F', $line['spacing']) }}pt"@endif>@foreach ($line['runs'] as $run)<span @class(['tebal' => $run['bold']])>{{ $run['text'] }}</span>@endforeach</div>
@endforeach
