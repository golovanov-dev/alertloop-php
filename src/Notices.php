<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

use Golovanov\AlertLoop\Channel\Message;

/**
 * Built-in alert texts in English and Russian.
 *
 * @internal
 */
final class Notices
{
    public const OPENED = 'opened';
    public const REMINDER = 'reminder';
    public const RESOLVED = 'resolved';
    public const SILENT = 'silent';
    /** An outbox incident resolved by a delivery. */
    public const DELIVERED = 'delivered';

    private const TEXTS = [
        'en' => [
            Notices::OPENED => '{severity}. Opened {time}',
            Notices::REMINDER => '{severity}, still open since {time} ({duration})',
            Notices::RESOLVED => 'Resolved, downtime {duration}',
            Notices::SILENT => 'Resolved: no repeats for {silence} min. Downtime {duration}',
            Notices::DELIVERED => 'Restored, downtime {duration}. In queue: {queued}, not delivered: {failed}',
            'repeats' => 'Repeats: {count}',
            'queued' => 'In queue: {queued}',
            'outbox_failing' => 'Outbox {name}: delivery fails',
            'outbox_restored' => 'Outbox {name}: delivery restored',
            'dead' => 'Outbox {name}: item #{id} is not delivered',
            'dead_text' => 'Attempts: {attempts}. Last error: {error}',
            'test' => 'Test message from alertloop check',
            'time' => 'Y-m-d H:i T',
            'min' => 'min',
            'h' => 'h',
            'd' => 'd',
            'info' => 'Info',
            'success' => 'Success',
            'warning' => 'Warning',
            'error' => 'Error',
            'critical' => 'Critical',
        ],
        'ru' => [
            Notices::OPENED => '{severity}. Открыт {time}',
            Notices::REMINDER => '{severity}, не устранено с {time} ({duration})',
            Notices::RESOLVED => 'Восстановлено, простой {duration}',
            Notices::SILENT => 'Закрыт: повторов не было {silence} мин. Простой {duration}',
            Notices::DELIVERED => 'Восстановлено, простой {duration}. В очереди: {queued}, не доставлено: {failed}',
            'repeats' => 'Повторов: {count}',
            'queued' => 'В очереди: {queued}',
            'outbox_failing' => 'Outbox {name}: сбой доставки',
            'outbox_restored' => 'Outbox {name}: доставка восстановлена',
            'dead' => 'Outbox {name}: элемент #{id} не доставлен',
            'dead_text' => 'Попыток: {attempts}. Последняя ошибка: {error}',
            'test' => 'Проверочное сообщение от alertloop check',
            'time' => 'd.m.Y H:i T',
            'min' => 'мин',
            'h' => 'ч',
            'd' => 'д',
            'info' => 'Информация',
            'success' => 'Успех',
            'warning' => 'Предупреждение',
            'error' => 'Ошибка',
            'critical' => 'Критично',
        ],
    ];

    /** @var array<string, string> */
    private readonly array $texts;

    public function __construct(
        private readonly string $source,
        string $language,
        private readonly \DateTimeZone $timezone,
    ) {
        $this->texts = self::TEXTS[$language] ?? self::TEXTS['en'];
    }

    public function message(Incident $incident, string $notice, \DateTimeImmutable $now): Message
    {
        $end = $incident->resolvedAt ?? $now;
        $line = strtr($this->texts[$notice], [
            '{severity}' => $this->texts[$incident->severity->value],
            '{time}' => $incident->openedAt->setTimezone($this->timezone)->format($this->texts['time']),
            '{duration}' => $this->duration($end->getTimestamp() - $incident->openedAt->getTimestamp()),
            '{silence}' => (string) $incident->resolveAfter,
            '{queued}' => (string) $incident->outboxQueued,
            '{failed}' => (string) $incident->outboxFailed,
        ]);
        $title = $incident->title;
        if ($notice === self::DELIVERED) {
            // An outbox recovery: its own title, and only the counts, not the error it opened with.
            $title = strtr($this->texts['outbox_restored'], ['{name}' => substr($incident->key, strlen('outbox.'))]);
        } else {
            if ($notice === self::OPENED && $incident->outboxQueued !== null) {
                $line .= '. ' . strtr($this->texts['queued'], ['{queued}' => (string) $incident->outboxQueued]);
            }
            if ($incident->occurrences > 1) {
                $line .= '. ' . strtr($this->texts['repeats'], ['{count}' => (string) ($incident->occurrences - 1)]);
            }
            if ($incident->text !== '') {
                $line .= "\n" . $incident->text;
            }
        }

        return new Message(
            $incident->severity,
            $this->source . ': ' . $title,
            $line,
            $incident->fields,
            $incident->key,
            $incident->resolvedAt === null ? 'firing' : 'resolved',
        );
    }

    /**
     * Title of the incident an outbox opens on its first failure.
     */
    public function outboxFailing(string $name): string
    {
        return strtr($this->texts['outbox_failing'], ['{name}' => $name]);
    }

    /**
     * The one message about an item that is not delivered. Never the payload.
     */
    public function deadItem(string $name, int $id, int $attempts, string $error): Message
    {
        return new Message(
            Severity::Error,
            $this->source . ': ' . strtr($this->texts['dead'], ['{name}' => $name, '{id}' => (string) $id]),
            strtr($this->texts['dead_text'], ['{attempts}' => (string) $attempts, '{error}' => $error]),
        );
    }

    public function test(): Message
    {
        return new Message(Severity::Info, $this->source . ': ' . $this->texts['test']);
    }

    private function duration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 60) {
            return $minutes . ' ' . $this->texts['min'];
        }
        if ($minutes < 24 * 60) {
            return intdiv($minutes, 60) . ' ' . $this->texts['h'] . ' ' . ($minutes % 60) . ' ' . $this->texts['min'];
        }

        return intdiv($minutes, 24 * 60) . ' ' . $this->texts['d'] . ' '
            . intdiv($minutes % (24 * 60), 60) . ' ' . $this->texts['h'];
    }
}
