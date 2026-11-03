<?php

declare(strict_types=1);

use App\Mail\Transport\RecipientAllowlist;

/*
| Local development only (D-121): which addresses may get real mail. Everything else stays in Mailpit, so a
| fictional demo address never reaches the mail provider.
*/

it('allows an address that is on the list, whatever the letter case and the spaces', function (): void {
    $list = RecipientAllowlist::fromString(' Tim.Uji@Example.test , kedua@example.test ');

    expect($list->allows('tim.uji@example.test'))->toBeTrue()
        ->and($list->allows('TIM.UJI@EXAMPLE.TEST'))->toBeTrue()
        ->and($list->allows(' kedua@example.test '))->toBeTrue()
        ->and($list->allows('ketiga@example.test'))->toBeFalse();
});

it('allows nothing when the list is empty', function (string $raw): void {
    $list = RecipientAllowlist::fromString($raw);

    expect($list->isEmpty())->toBeTrue()
        ->and($list->allows('tim.uji@example.test'))->toBeFalse();
})->with(['', '   ', ',', ' , ; ']);

it('reads entries split by commas, semicolons, spaces or lines', function (): void {
    $list = RecipientAllowlist::fromString("a@example.test;b@example.test\nc@example.test d@example.test");

    expect($list->patterns())->toBe(['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test']);
});

it('lets a star stand for a part of the name before the @', function (): void {
    $list = RecipientAllowlist::fromString('tim.uji+*@example.test');

    expect($list->allows('tim.uji+ketua@example.test'))->toBeTrue()
        ->and($list->allows('tim.uji+guru.pembimbing@example.test'))->toBeTrue()
        ->and($list->allows('tim.uji+@example.test'))->toBeTrue()
        ->and($list->allows('tim.uji@example.test'))->toBeFalse()
        ->and($list->allows('orang.lain+ketua@example.test'))->toBeFalse()
        ->and($list->allows('tim.uji+ketua@example.test.evil.test'))->toBeFalse()
        ->and($list->allows('tim.uji+a@b@example.test'))->toBeFalse();
});

it('treats a dot in an entry as a dot, not as any character', function (): void {
    $list = RecipientAllowlist::fromString('tim.uji@example.test');

    expect($list->allows('timxuji@example.test'))->toBeFalse()
        ->and($list->allows('tim.uji@examplextest'))->toBeFalse();
});

it('drops an entry that would open a whole domain or is not an address, and remembers it', function (string $entry): void {
    $list = RecipientAllowlist::fromString("baik@example.test, {$entry}");

    expect($list->patterns())->toBe(['baik@example.test'])
        ->and($list->rejected())->toBe([strtolower($entry)])
        ->and($list->allows('siapa.saja@example.test'))->toBeFalse();
})->with([
    'two stars' => ['**'],
    'a whole domain' => ['*@example.test'],
    'only stars before the @' => ['**@example.test'],
    'a star in the domain' => ['tim@*.test'],
    'a star instead of the domain' => ['tim@*'],
    'no @' => ['example.test'],
    'two @' => ['tim@uji@example.test'],
    'no name' => ['@example.test'],
    'no dot in the domain' => ['tim@localhost'],
    'a character that is not allowed' => ['tim(uji)@example.test'],
]);

it('never allows an empty or malformed recipient', function (string $address): void {
    $list = RecipientAllowlist::fromString('tim.uji+*@example.test, tim.uji@example.test');

    expect($list->allows($address))->toBeFalse();
})->with(['', '   ', 'tim.uji', '@example.test', "tim.uji@example.test\nBcc: lain@example.test"]);

it('opens the list with a single star, but never to an address that cannot exist (D-130)', function (): void {
    $list = RecipientAllowlist::fromString('*');

    expect($list->isOpen())->toBeTrue()
        ->and($list->isEmpty())->toBeFalse()
        ->and($list->rejected())->toBe([])
        ->and($list->allows('siapa.saja@gmail.com'))->toBeTrue()
        ->and($list->allows('Guru.Uji@sman3-smg.sch.id'))->toBeTrue()
        ->and($list->allows('nadia@sth.test'))->toBeFalse()
        ->and($list->allows('nadia@STH.TEST'))->toBeFalse()
        ->and($list->allows('orang@mail.sth.test'))->toBeFalse()
        ->and($list->allows('orang@example.com'))->toBeFalse()
        ->and($list->allows('orang@sub.example.org'))->toBeFalse()
        ->and($list->allows('orang@contoh.invalid'))->toBeFalse()
        ->and($list->allows('orang@pc.local'))->toBeFalse()
        ->and($list->allows('orang@localhost'))->toBeFalse()
        ->and($list->allows('orang@notexample.com'))->toBeTrue()
        ->and($list->allows('orang@contest.id'))->toBeTrue()
        // Written as numbers, a host is not a mailbox anybody has.
        ->and($list->allows('orang@127.0.0.1'))->toBeFalse()
        ->and($list->allows('orang@gmail.com.'))->toBeFalse()
        ->and($list->allows("orang@gmail.com\nBcc: lain@gmail.com"))->toBeFalse()
        ->and($list->allows('orang@a@gmail.com'))->toBeFalse();
});

it('does not open the list when the star stands next to an entry it did not understand', function (string $raw, array $rejected): void {
    // "* @gmail.com" is what a slip of "nama+*@gmail.com" leaves behind: before D-130 it delivered nothing, and it
    // still must not deliver to everybody.
    $list = RecipientAllowlist::fromString($raw);

    expect($list->isOpen())->toBeFalse()
        ->and($list->openRefused())->toBeTrue()
        ->and($list->rejected())->toBe($rejected)
        ->and($list->allows('siapa.saja@gmail.com'))->toBeFalse()
        ->and($list->allows('nadia@sth.test'))->toBeFalse();
})->with([
    'a space inside a pattern' => ['* @gmail.com', ['@gmail.com']],
    'a comma inside a pattern' => ['*,@gmail.com', ['@gmail.com']],
    'a whole domain next to the star' => ['*, *@gmail.com', ['*@gmail.com']],
    'a word that is not an address' => ['* semua', ['semua']],
]);

it('keeps what is listed deliverable when the star is refused, and reports nothing for a clean list', function (): void {
    $refused = RecipientAllowlist::fromString('*, tim.uji@example.test, @gmail.com');
    $open = RecipientAllowlist::fromString('*, tim.uji@example.test');

    expect($refused->allows('tim.uji@example.test'))->toBeTrue()
        ->and($refused->isEmpty())->toBeFalse()
        ->and($open->isOpen())->toBeTrue()
        ->and($open->openRefused())->toBeFalse()
        ->and(RecipientAllowlist::fromString('tim.uji@example.test')->openRefused())->toBeFalse();
});

it('keeps a listed address deliverable in open mode and stays closed without the star', function (): void {
    $open = RecipientAllowlist::fromString('*, tim.uji@example.test');
    $closed = RecipientAllowlist::fromString('tim.uji@example.test');

    expect($open->allows('tim.uji@example.test'))->toBeTrue()
        ->and($open->allows('lain@example.test'))->toBeFalse()
        ->and($closed->isOpen())->toBeFalse()
        ->and($closed->allows('siapa.saja@gmail.com'))->toBeFalse();
});
