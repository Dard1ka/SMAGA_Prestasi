<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/*
| QA9: the D-121 block of .env.example ("Real mail from a local stack") is switched on by removing the leading "#   "
| of its lines. Each line must then still be valid for phpdotenv: an explanation after a value without "#" (or a
| placeholder with spaces) stops every process with "Encountered unexpected whitespace".
*/

/** The commented setting lines of .env.example ("#   NAME=…"), without their comment mark. @return list<string> */
function qa9EnvExampleBlock(): array
{
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);

    return array_values(array_map(
        fn (string $line): string => substr($line, 4),
        array_filter($lines, fn (string $line): bool => preg_match('/^# {3}[A-Z][A-Z0-9_]*=/', $line) === 1),
    ));
}

it('parses the real-mail block of .env.example once its lines are uncommented (QA9)', function (): void {
    $block = qa9EnvExampleBlock();

    expect($block)->toHaveCount(7)
        ->and(Dotenv::parse(implode("\n", $block)))->toBe([
            'MAIL_MAILER' => 'allowlist',
            'MAIL_HOST' => 'smtp-relay.brevo.com',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_FROM_ADDRESS' => '',
            'STH_MAIL_ALLOWLIST' => 'you@example.test,you+*@example.test',
        ]);
});

it('keeps the whole .env.example valid with the block switched on, the block winning over the Mailpit lines (QA9)', function (): void {
    $content = (string) preg_replace('/^# {3}(?=[A-Z][A-Z0-9_]*=)/m', '', (string) file_get_contents(base_path('.env.example')));

    $env = Dotenv::parse($content);

    expect($env['MAIL_MAILER'])->toBe('allowlist')
        ->and($env['MAIL_HOST'])->toBe('smtp-relay.brevo.com')
        ->and($env['STH_MAIL_ALLOWLIST'])->toBe('you@example.test,you+*@example.test');
});
