<?php

declare(strict_types=1);

namespace App\Actions\Trash;

use Illuminate\Validation\ValidationException;

/**
 * The reason the Admin types before a class or students go to the trash (Admin › Kelas): required, MIN–MAX characters
 * after trimming, stored on every trash entry and in the audit row.
 */
final class TrashReason
{
    public const MIN = 10;

    public const MAX = 500;

    /**
     * The trimmed reason, or a ValidationException under $key.
     *
     * @throws ValidationException
     */
    public static function validated(string $reason, string $key = 'reason'): string
    {
        $reason = trim($reason);

        $message = match (true) {
            $reason === '' => 'Isi alasannya.',
            mb_strlen($reason) < self::MIN => 'Alasan minimal '.self::MIN.' karakter.',
            mb_strlen($reason) > self::MAX => 'Alasan maksimal '.self::MAX.' karakter.',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $reason;
    }
}
