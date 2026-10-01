<?php

declare(strict_types=1);

namespace App\Domain\Registrations;

use DomainException;

/**
 * Thrown when a registration status change is not allowed by ARCHITECTURE §6.
 * The message is safe to show to users; from/to are kept for logs.
 */
final class InvalidTransition extends DomainException
{
    public const MESSAGE = 'Aksi ini tidak bisa dilakukan pada status pendaftaran sekarang.';

    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct(self::MESSAGE);
    }
}
