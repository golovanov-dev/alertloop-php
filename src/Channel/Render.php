<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * Message rendering shared by the built-in channels.
 *
 * @internal
 */
final class Render
{
    /**
     * Title in bold, then text, then one line per field: HTML as accepted by
     * Telegram and MAX.
     */
    public static function html(Message $message): string
    {
        $html = '<b>' . self::escape($message->title) . '</b>';
        if ($message->text !== '') {
            $html .= "\n" . self::escape($message->text);
        }
        if ($message->fields !== []) {
            $html .= "\n";
            foreach ($message->fields as $name => $value) {
                $html .= "\n<b>" . self::escape($name) . '</b>: ' . self::escape($value);
            }
        }

        return $html;
    }

    /**
     * Text and fields as plain text; the title goes to the e-mail subject.
     */
    public static function plainBody(Message $message): string
    {
        $lines = [];
        foreach ($message->fields as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($lines === []) {
            return $message->text;
        }

        return ($message->text === '' ? '' : $message->text . "\n\n") . implode("\n", $lines);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
