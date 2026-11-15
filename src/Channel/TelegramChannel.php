<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * Telegram Bot API sendMessage. $apiBase is api.telegram.org or a mirror with
 * the same URL layout.
 */
final class TelegramChannel implements Channel
{
    public function __construct(
        private readonly string $token,
        private readonly string $apiBase = 'https://api.telegram.org',
    ) {
    }

    public function send(string $to, Message $message): Result
    {
        $response = Http::postJson(
            rtrim($this->apiBase, '/') . '/bot' . $this->token . '/sendMessage',
            [],
            [
                'chat_id' => $to,
                'text' => Render::html($message),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ],
        );
        $status = $response['status'];
        if ($status === 0) {
            return Result::retryable($response['error']);
        }
        if ($status === 200) {
            return Result::ok();
        }
        $error = Http::describe($status, $response['body'], 'description');
        if ($status === 429) {
            $data = json_decode($response['body'], true);
            $retryAfter = is_array($data) && is_array($data['parameters'] ?? null)
                ? ($data['parameters']['retry_after'] ?? null)
                : null;

            return Result::retryable($error, is_int($retryAfter) ? $retryAfter : null);
        }
        if ($status >= 400 && $status < 500) {
            return Result::permanent($error);
        }

        return Result::retryable($error);
    }

    /**
     * Calls getMe: checks that the API is reachable and accepts the token.
     *
     * @internal used by alertloop check
     * @return string the bot name
     * @throws \RuntimeException with the reason
     */
    public function check(): string
    {
        $base = rtrim($this->apiBase, '/');

        return Http::botName($base, Http::get($base . '/bot' . $this->token . '/getMe', []), 'description');
    }
}
