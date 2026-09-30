<?php

namespace App\Http\Middleware;

use App\Exceptions\SftpException;
use App\Services\SftpConnectionPool;
use App\Services\SftpService;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the SFTP connection for the current request: reused from the pool when running under
 * Laravel Octane (fast, like FileZilla), otherwise re-opened from the short-lived session.
 *
 * The SFTP password is never stored in plain text: the session only holds the password
 * encrypted with a random per-connection key, and that key lives only in an encrypted,
 * HttpOnly browser cookie that expires when the browser closes. Neither half is useful alone.
 */
class EnsureSftpConnected
{
    public const SESSION_KEY = 'sftp';

    public const COOKIE_KEY = 'sftp_key';

    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->session()->get(self::SESSION_KEY);
        $key = $request->cookie(self::COOKIE_KEY);

        if (! is_array($data) || ! is_string($key)) {
            return $this->notConnected($request, 'انتهت جلسة الاتصال. يرجى الاتصال مجددًا.');
        }

        $started = microtime(true);
        $poolKey = SftpConnectionPool::enabled() ? SftpConnectionPool::key($key, $data['host'], (int) $data['port'], $data['username']) : null;
        $sftp = $poolKey !== null ? SftpConnectionPool::take($poolKey, $data['fingerprint'], $data['root']) : null;
        $reused = $sftp !== null;

        if (! $reused) {
            try {
                $sftp = $this->open($data, $key);
            } catch (DecryptException) {
                $this->endSession($request);

                return $this->notConnected($request, 'انتهت جلسة الاتصال. يرجى الاتصال مجددًا.');
            } catch (SftpException $e) {
                if ($e->status === 409 || $e->status === 401) {
                    // Host key changed, or the credentials no longer work: this session is over.
                    $this->endSession($request);

                    return $this->notConnected($request, $e->getMessage());
                }

                // Server unreachable for now: keep the session so the next click simply retries.
                return $this->unavailable($request, $e->getMessage());
            }
        }
        $sftp->setResolvedRoot($data['root']);

        if ($poolKey !== null) {
            $fingerprint = $data['fingerprint'];
            $sftp->onRelease(fn (SftpService $connection) => SftpConnectionPool::put($poolKey, $fingerprint, $connection));
        }

        app()->instance(SftpService::class, $sftp);
        $connected = microtime(true);
        $healthy = false;

        try {
            $response = $next($request);
            // A 5xx may mean the connection broke mid-operation: never put such a connection back in the pool.
            $healthy = $response->getStatusCode() < 500;
            // Visible in the browser DevTools (Network → Timing): connection time vs. the operation itself.
            $response->headers->set('Server-Timing', sprintf(
                'sftp-connect;dur=%.0f;desc="%s", sftp-operation;dur=%.0f;desc="Operation"',
                ($connected - $started) * 1000,
                $reused ? 'Reused open connection' : 'SFTP connect + login',
                (microtime(true) - $connected) * 1000,
            ));

            return $response;
        } finally {
            if (! $healthy) {
                $sftp->onRelease(null);
            }
            // Streamed downloads release the connection themselves once the file has been sent.
            if (! $request->attributes->get('sftp_keep_open')) {
                $sftp->release();
            }
        }
    }

    /**
     * Open a new connection with the credentials from the session.
     *
     * @param  array<string, mixed>  $data
     */
    private function open(array $data, string $key): SftpService
    {
        $plain = (new Encrypter(base64_decode($key), 'aes-256-gcm'))->decryptString($data['secret']);
        $credentials = json_decode($plain, true);
        if (! is_array($credentials)) {
            $credentials = ['password' => $plain]; // sessions created before SSH-key support
        }
        unset($plain);

        return SftpService::connect(
            $data['host'],
            $data['port'],
            $data['username'],
            $credentials,
            $data['fingerprint'],
            SftpService::MSG_CONNECTION_LOST,
        );
    }

    /**
     * Forget the connection so the connect page no longer sends the user back to the file manager
     * (otherwise the two pages would redirect to each other forever).
     */
    private function endSession(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    private function unavailable(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        return response($message, 503);
    }

    private function notConnected(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reconnect' => true], 401);
        }

        return redirect()->route('home')->with('error', $message);
    }
}
