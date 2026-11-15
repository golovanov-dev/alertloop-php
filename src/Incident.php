<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * One row of the incidents table. Times are UTC.
 *
 * @internal
 */
final class Incident
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $text,
        public readonly array $fields,
        public readonly int $occurrences,
        public readonly \DateTimeImmutable $openedAt,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly ?int $resolveAfter,
        public readonly ?string $pendingNotice,
        /** Items in the queue when an outbox incident opened, then when it resolved; null for other incidents. */
        public readonly ?int $outboxQueued = null,
        public readonly int $outboxFailed = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $fields = [];
        $decoded = json_decode(self::str($row['fields']), true);
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            $fields[(string) $name] = self::str($value);
        }

        return new self(
            (int) self::str($row['id']),
            self::str($row['dedupe_key']),
            Severity::from(self::str($row['severity'])),
            self::str($row['title']),
            self::str($row['text']),
            $fields,
            (int) self::str($row['occurrences']),
            self::time(self::str($row['opened_at'])),
            $row['resolved_at'] === null ? null : self::time(self::str($row['resolved_at'])),
            $row['resolve_after'] === null ? null : (int) self::str($row['resolve_after']),
            $row['pending_notice'] === null ? null : self::str($row['pending_notice']),
            $row['outbox_queued'] === null ? null : (int) self::str($row['outbox_queued']),
            (int) self::str($row['outbox_failed']),
        );
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function time(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
