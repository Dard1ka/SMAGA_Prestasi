<?php

declare(strict_types=1);

namespace App\Mail\Transport;

/**
 * The addresses that may get real mail from a local development stack (STH_MAIL_ALLOWLIST, D-121).
 *
 * One entry is a whole address, or an address whose name part (before the at sign) holds a star: the name
 * "tim.uji+*" stands for every plus-address of the mailbox tim.uji. An entry that would open a whole domain
 * (a name of stars only), a star in the domain or anything that is not an address is dropped and kept in
 * rejected(), so a typing mistake can only send less mail out, never more. Examples: .env.example and
 * tests/Unit/Mail/RecipientAllowlistTest.php (no address is written here: an at sign reads as a tag in a docblock).
 */
final readonly class RecipientAllowlist
{
    /** Name part: at least one character that is not a star. Domain: plain host name with a dot, never a star. */
    private const ENTRY = '/^(?=[^@]*[a-z0-9._%+\-])[a-z0-9._%+\-*]+@[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)+$/D';

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $rejected
     */
    private function __construct(
        private array $patterns,
        private array $rejected,
    ) {}

    /** Entries split by commas, semicolons, spaces or line breaks; letter case does not matter. */
    public static function fromString(string $raw): self
    {
        $patterns = [];
        $rejected = [];

        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (preg_match(self::ENTRY, $entry) === 1) {
                $patterns[] = $entry;
            } else {
                $rejected[] = $entry;
            }
        }

        return new self(array_values(array_unique($patterns)), array_values(array_unique($rejected)));
    }

    public function allows(string $address): bool
    {
        $address = strtolower(trim($address));

        // Exactly one "@" and no white space: a header-injection attempt or a list of addresses is never allowed.
        if (preg_match('/^[^\s@]+@[^\s@]+$/D', $address) !== 1) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            $regex = '/^'.str_replace('\*', '[^\s@]*', preg_quote($pattern, '/')).'$/D';

            if (preg_match($regex, $address) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /** @return list<string> entries that were typed but are not used */
    public function rejected(): array
    {
        return $this->rejected;
    }
}
