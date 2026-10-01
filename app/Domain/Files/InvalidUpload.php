<?php

declare(strict_types=1);

namespace App\Domain\Files;

use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * An upload that StoreUpload refused (type, content, size, active PDF content, oversized image).
 * Messages are short Indonesian texts, safe to show to the user.
 */
final class InvalidUpload extends DomainException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'File tidak bisa diproses.');
    }

    /** Convenience for Livewire/FormRequest callers: report the errors on one form field. */
    public function toValidationException(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => $this->errors]);
    }
}
