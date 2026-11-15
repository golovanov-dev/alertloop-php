<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * MAX bot API. $caFile is a PEM file with the root certificate the API needs
 * when the system CA store lacks it.
 */
final class MaxChannel implements Channel
{
    public function __construct(
        private readonly string $token,
        private readonly ?string $caFile = null,
        private readonly string $apiBase = 'https://platform-api2.max.ru',
    ) {
    }

    public function send(string $to, Message $message): Result
    {
        $response = Http::postJson(
            rtrim($this->apiBase, '/') . '/messages?chat_id=' . rawurlencode($to),
            ['Authorization: ' . $this->token],
            ['text' => Render::html($message), 'format' => 'html'],
            $this->caFile,
        );
        $status = $response['status'];
        if ($status === 0) {
            return Result::retryable($response['error']);
        }
        if ($status === 200) {
            return Result::ok();
        }
        $error = Http::describe($status, $response['body'], 'message');
        if ($status === 429) {
            $retryAfter = $response['headers']['retry-after'] ?? '';

            return Result::retryable($error, ctype_digit($retryAfter) ? (int) $retryAfter : null);
        }
        if ($status >= 400 && $status < 500) {
            return Result::permanent($error);
        }

        return Result::retryable($error);
    }

    /**
     * Calls GET /me: checks that the API is reachable and accepts the token.
     *
     * @internal used by alertloop check
     * @return string the bot name
     * @throws \RuntimeException with the reason
     */
    public function check(): string
    {
        $base = rtrim($this->apiBase, '/');

        return Http::botName($base, Http::get($base . '/me', ['Authorization: ' . $this->token], $this->caFile), 'message');
    }
}
