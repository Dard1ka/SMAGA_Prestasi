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
    'every address' => ['*'],
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
