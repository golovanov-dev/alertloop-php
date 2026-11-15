<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * Minimal JSON POST over ext-curl shared by the HTTP channels.
 *
 * @internal
 */
final class Http
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $json
     * @return array{status: int, headers: array<string, string>, body: string, error: string}
     *         status 0 means the request did not reach the server
     */
    public static function postJson(string $url, array $headers, array $json, ?string $caFile = null): array
    {
        return self::request($url, array_merge(['Content-Type: application/json'], $headers), [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ], $caFile);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, error: string}
     */
    public static function get(string $url, array $headers, ?string $caFile = null): array
    {
        return self::request($url, $headers, [], $caFile);
    }

    /**
     * @param list<string> $headers
     * @param array<int, mixed> $options
     * @return array{status: int, headers: array<string, string>, body: string, error: string}
     */
    private static function request(string $url, array $headers, array $options, ?string $caFile): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $options += [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($caFile !== null && $caFile !== '') {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);

        return [
            'status' => $errno === 0 ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
            'error' => $errno === 0 ? '' : 'curl error ' . $errno . ': ' . curl_error($ch),
        ];
    }

    /**
     * The bot name from a Telegram getMe or MAX GET /me answer.
     *
     * @param array{status: int, headers: array<string, string>, body: string, error: string} $response
     * @throws \RuntimeException the request failed or the token was rejected
     */
    public static function botName(string $url, array $response, string $errorField): string
    {
        if ($response['status'] === 0) {
            throw new \RuntimeException('cannot reach ' . $url . ': ' . $response['error']);
        }
        $data = json_decode($response['body'], true);
        $bot = is_array($data) && is_array($data['result'] ?? null) ? $data['result'] : $data;
        $name = is_array($bot) ? ($bot['username'] ?? $bot['name'] ?? null) : null;
        if ($response['status'] !== 200 || !is_string($name)) {
            throw new \RuntimeException($url . ': ' . self::describe($response['status'], $response['body'], $errorField));
        }

        return $name;
    }

    /**
     * "HTTP <status>: <detail>", the detail taken from a field of a JSON body.
     */
    public static function describe(int $status, string $body, string $field): string
    {
        $data = json_decode($body, true);
        $detail = is_array($data) && isset($data[$field]) && is_scalar($data[$field]) ? (string) $data[$field] : '';

        return 'HTTP ' . $status . ($detail === '' ? '' : ': ' . $detail);
    }
}
