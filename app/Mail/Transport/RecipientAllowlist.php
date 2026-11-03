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
 *
 * The one entry that is a single star opens the list (D-130): every address may get real mail, except an address
 * that cannot exist (isFictional(): the demo data, the factories and the scheduler write to those). A listed
 * address still goes out as before, so a tester's own entry is never taken away by the open mode. The star only
 *
 * counts when every other entry was understood: "* @gmail.com" (a slip for "nama+*@gmail.com") leaves a rejected
 * entry behind, and then the list stays closed (openRefused()), so the rule above still holds: a typing mistake
 * never sends more mail out than the list did before.
 */
final readonly class RecipientAllowlist
{
    /** The entry that opens the list. */
    public const OPEN = '*';

    /** Top-level names nobody can register (RFC 2606 / 6761 / 6762) and the documentation domains of RFC 2606. */
    private const FICTIONAL = '/(?:\.(?:test|example|invalid|localhost|local)|(?:^|\.)example\.(?:com|net|org))$/D';

    /**
     * What the open mode accepts as a domain that can exist: a host name with a dot whose last part starts with a
     * letter (so neither "localhost" nor an address written as numbers, 127.0.0.1).
     */
    private const HOST = '/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z](?:[a-z0-9\-]*[a-z0-9])?$/D';

    /** Name part: at least one character that is not a star. Domain: plain host name with a dot, never a star. */
    private const ENTRY = '/^(?=[^@]*[a-z0-9._%+\-])[a-z0-9._%+\-*]+@[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)+$/D';

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $rejected
     */
    private function __construct(
        private array $patterns,
        private array $rejected,
        private bool $open = false,
        private bool $openRefused = false,
    ) {}

    /** Entries split by commas, semicolons, spaces or line breaks; letter case does not matter. */
    public static function fromString(string $raw): self
    {
        $patterns = [];
        $rejected = [];
        $star = false;

        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if ($entry === self::OPEN) {
                $star = true;
            } elseif (preg_match(self::ENTRY, $entry) === 1) {
                $patterns[] = $entry;
            } else {
                $rejected[] = $entry;
            }
        }

        // A star next to an entry nobody understood is most likely a broken pattern, not a wish to open the list.
        $open = $star && $rejected === [];

        return new self(array_values(array_unique($patterns)), array_values(array_unique($rejected)), $open, $star && ! $open);
    }

    /** True for an address whose domain cannot exist: the demo data (name at sth.test), Faker's safe addresses. */
    public static function isFictional(string $address): bool
    {
        $domain = strtolower(trim(substr(strrchr($address, '@') ?: '', 1)));

        return preg_match(self::HOST, $domain) !== 1 || preg_match(self::FICTIONAL, '.'.$domain) === 1;
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

        return $this->open && ! self::isFictional($address);
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [] && ! $this->open;
    }

    /** The list holds the single star and nothing it did not understand: every address that can exist goes out. */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /** The star was typed next to an entry that was dropped, so it does not count (see the class comment). */
    public function openRefused(): bool
    {
        return $this->openRefused;
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
