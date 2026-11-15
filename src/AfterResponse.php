<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Work that must not keep the visitor waiting. In a web request it runs from
 * one shutdown function, after fastcgi_finish_request() or
 * litespeed_finish_request() has sent the response; without them, at the end
 * of the request. The site's session is closed first, so the visitor's next
 * request does not wait for this work. In CLI it runs at once.
 *
 * @internal
 */
final class AfterResponse
{
    /** @var list<array{string, \Closure(): void}> */
    private array $tasks = [];
    private bool $registered = false;

    public function __construct(private readonly bool $immediate = PHP_SAPI === 'cli')
    {
    }

    /**
     * @param string $what names the work in the error log
     * @param \Closure(): void $task at once, its exceptions reach the caller; after the response they are logged
     */
    public function defer(string $what, \Closure $task): void
    {
        if ($this->immediate) {
            $task();

            return;
        }
        $this->tasks[] = [$what, $task];
        if (!$this->registered) {
            register_shutdown_function($this->run(...));
            $this->registered = true;
        }
    }

    private function run(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        while (($task = array_shift($this->tasks)) !== null) {
            [$what, $work] = $task;
            try {
                $work();
            } catch (\Throwable $e) {
                error_log("alertloop: {$what} failed: " . $e->getMessage());
            }
        }
    }
}
