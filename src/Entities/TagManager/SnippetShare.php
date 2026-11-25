<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * A read-only link to one saved tag's developer snippet. Only a hash of the
 * link's token is kept, so the YAML that holds it cannot open the page; the
 * token is shown once, when the link is made. A link stops working when it
 * expires or its tag stops being shared.
 */
final class SnippetShare
{
    /** The operator setting for how long a new link lasts, in days. */
    public const SETTING = 'tag_snippet_share_days';
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 365;
    public const TOKEN_PATTERN = '/^[a-f0-9]{48}$/D';

    public function __construct(
        public readonly string $tagId,
        public readonly string $hash,
        public readonly \DateTimeImmutable $expires,
    ) {
    }

    /** A new link for the tag, lasting the given days from now, and its token. */
    public static function create(
        string $tagId,
        int $days,
        \DateTimeImmutable $now,
        string $token,
    ): self {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('A share token is 48 lowercase hex characters.');
        }

        return new self($tagId, self::hashOf($token), $now->modify('+'.self::days($days).' days'));
    }

    /** The number of days a link lasts, checked as the settings check it. */
    public static function days(mixed $value): int
    {
        $valid = is_int($value)
            || (is_string($value) && preg_match('/^[0-9]{1,3}$/D', $value) === 1);
        if (!$valid || (int) $value < 1 || (int) $value > self::MAX_DAYS) {
            throw new \InvalidArgumentException(sprintf(
                'A shared snippet link lasts a whole number of days from 1 to %d.',
                self::MAX_DAYS,
            ));
        }

        return (int) $value;
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function opensWith(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1
            && hash_equals($this->hash, self::hashOf($token));
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expires;
    }

    /**
     * The links still open, dropping expired ones and those of tags no longer saved.
     *
     * @param list<self> $shares
     * @param list<string> $savedTagIds
     * @return list<self>
     */
    public static function current(
        array $shares,
        array $savedTagIds,
        \DateTimeImmutable $now,
    ): array {
        return array_values(array_filter(
            $shares,
            static fn (self $share): bool => !$share->isExpiredAt($now)
                && in_array($share->tagId, $savedTagIds, true),
        ));
    }

    /** @return array{tag: string, hash: string, expires: string} */
    public function toArray(): array
    {
        return [
            'tag' => $this->tagId,
            'hash' => $this->hash,
            'expires' => $this->expires->format(\DateTimeInterface::ATOM),
        ];
    }

    /** A saved link, or null when the YAML entry is not one. */
    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['tag'] ?? null)
            || !is_string($row['hash'] ?? null) || !is_string($row['expires'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $row['hash']) !== 1) {
            return null;
        }
        $expires = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $row['expires']);

        return $expires === false ? null : new self($row['tag'], $row['hash'], $expires);
    }
}
