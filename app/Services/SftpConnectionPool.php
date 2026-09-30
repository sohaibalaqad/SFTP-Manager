<?php

namespace App\Services;

/**
 * Keeps logged-in SFTP connections open between requests (like FileZilla does), so a click no longer
 * pays for a new SSH handshake + login.
 *
 * Only active under Laravel Octane, where the PHP worker process survives between requests; with the
 * classic `php artisan serve` / PHP-FPM every request still opens and closes its own connection.
 *
 * Each worker has its own pool (static memory). A connection is keyed by a hash of the per-connection
 * secret cookie, so only the browser holding that cookie can reuse it. While a request uses a
 * connection it is taken out of the pool, so it is never shared by two requests at once.
 */
class SftpConnectionPool
{
    /** Close connections not used for this many seconds. */
    public const IDLE_SECONDS = 300;

    /** Re-check a pooled connection with a cheap request if it has been idle longer than this. */
    private const VERIFY_AFTER_SECONDS = 15;

    /** @var array<string, array{sftp: SftpService, fingerprint: string, used: float}> */
    private static array $connections = [];

    public static function enabled(): bool
    {
        return (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE'));
    }

    public static function key(string $secretCookie, string $host, int $port, string $username): string
    {
        return hash('sha256', $secretCookie."\0".$host."\0".$port."\0".$username);
    }

    /**
     * Take a live connection out of the pool, or null when a new one must be opened.
     */
    public static function take(string $key, string $fingerprint, string $root): ?SftpService
    {
        self::closeIdle();

        $entry = self::$connections[$key] ?? null;
        unset(self::$connections[$key]);
        if ($entry === null) {
            return null;
        }

        $sftp = $entry['sftp'];
        $stale = microtime(true) - $entry['used'] > self::VERIFY_AFTER_SECONDS;
        if (! hash_equals($entry['fingerprint'], $fingerprint) || ! $sftp->isAlive($stale ? $root : null)) {
            $sftp->disconnect();

            return null;
        }

        return $sftp;
    }

    public static function put(string $key, string $fingerprint, SftpService $sftp): void
    {
        if (! $sftp->isAlive()) {
            $sftp->disconnect();

            return;
        }

        self::$connections[$key] = ['sftp' => $sftp, 'fingerprint' => $fingerprint, 'used' => microtime(true)];
    }

    public static function forget(string $key): void
    {
        if (isset(self::$connections[$key])) {
            self::$connections[$key]['sftp']->disconnect();
            unset(self::$connections[$key]);
        }
    }

    public static function count(): int
    {
        return count(self::$connections);
    }

    private static function closeIdle(): void
    {
        $limit = microtime(true) - self::IDLE_SECONDS;
        foreach (self::$connections as $key => $entry) {
            if ($entry['used'] < $limit) {
                $entry['sftp']->disconnect();
                unset(self::$connections[$key]);
            }
        }
    }
}
