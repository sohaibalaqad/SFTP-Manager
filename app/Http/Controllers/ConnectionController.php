<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureSftpConnected;
use App\Services\SftpConnectionPool;
use App\Services\SftpService;
use App\Services\TransferLog;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class ConnectionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->session()->has(EnsureSftpConnected::SESSION_KEY) && $request->hasCookie(EnsureSftpConnected::COOKIE_KEY)) {
            return redirect()->route('files');
        }

        return view('connect');
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]_]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'auth' => ['nullable', 'in:password,key'],
            'password' => ['nullable', 'string', 'max:1024'],
            'private_key' => ['required_if:auth,key', 'nullable', 'string', 'max:16384'],
            'passphrase' => ['nullable', 'string', 'max:1024'],
            'host_fingerprint' => ['nullable', 'string', 'max:100', 'regex:/^SHA256:[A-Za-z0-9+\/]+$/'],
            'path' => ['nullable', 'string', 'max:1024'],
        ], [
            'private_key.required_if' => 'اختر ملف مفتاح SSH الخاص أو الصقه.',
            'host.required' => 'أدخل عنوان السيرفر (Host).',
            'host.regex' => 'عنوان السيرفر (Host) غير صالح.',
            'port.*' => 'رقم المنفذ (Port) يجب أن يكون بين 1 و 65535.',
            'username.required' => 'أدخل اسم المستخدم (Username).',
            '*.max' => 'القيمة المدخلة طويلة جدًا.',
        ]);

        $credentials = ($data['auth'] ?? 'password') === 'key'
            ? ['key' => (string) $data['private_key'], 'passphrase' => (string) ($data['passphrase'] ?? '')]
            : ['password' => (string) ($data['password'] ?? '')];
        unset($data['password'], $data['private_key'], $data['passphrase']);
        $host = trim($data['host'], '[]');

        // Host key must be confirmed by the user (first time) and must match what they trusted.
        $sftp = SftpService::connect($host, (int) $data['port'], $data['username'], $credentials, $data['host_fingerprint'] ?? null, verifyHost: true);
        try {
            $root = $sftp->useRoot($data['path'] ?? '');
        } catch (\Throwable $e) {
            $sftp->disconnect();
            throw $e;
        }

        // Split secret: encrypted credentials (password or SSH key) in the session,
        // random key in an encrypted HttpOnly cookie.
        $key = random_bytes(32);

        // Under Octane keep this first connection open: the first folder listing then reuses it.
        if (SftpConnectionPool::enabled()) {
            SftpConnectionPool::put(SftpConnectionPool::key(base64_encode($key), $host, (int) $data['port'], $data['username']), $sftp->fingerprint, $sftp);
        } else {
            $sftp->disconnect();
        }
        $secret = (new Encrypter($key, 'aes-256-gcm'))->encryptString(json_encode($credentials));
        unset($credentials);

        // Fresh temporary transfer log for this connection.
        TransferLog::prune();
        $this->deleteLog($request);
        $this->closePooledConnection($request); // switching servers: close the previous one

        $request->session()->regenerate(true);
        $request->session()->put(EnsureSftpConnected::SESSION_KEY, [
            'name' => trim((string) ($data['name'] ?? '')) ?: $host,
            'host' => $host,
            'port' => (int) $data['port'],
            'username' => $data['username'],
            'auth' => $data['auth'] ?? 'password',
            'root' => $root,
            'secret' => $secret,
            'fingerprint' => $sftp->fingerprint,
            'log' => TransferLog::newId(),
        ]);

        Cookie::queue(Cookie::make(
            EnsureSftpConnected::COOKIE_KEY,
            base64_encode($key),
            0,           // browser-session cookie
            '/',
            null,
            config('session.secure'),
            true,        // HttpOnly: not readable from JavaScript
            false,
            'strict',
        ));

        return response()->json(['redirect' => route('files'), 'root' => $root]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->deleteLog($request);
        $this->closePooledConnection($request);
        $request->session()->forget(EnsureSftpConnected::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(EnsureSftpConnected::COOKIE_KEY));

        return redirect()->route('home')->with('status', 'تم قطع الاتصال.');
    }

    /**
     * Close the kept-open connection of this browser (in this worker; connections kept by other
     * workers can no longer be used without the cookie and close after the idle timeout).
     */
    private function closePooledConnection(Request $request): void
    {
        $data = $request->session()->get(EnsureSftpConnected::SESSION_KEY);
        $key = $request->cookie(EnsureSftpConnected::COOKIE_KEY);
        if (is_array($data) && is_string($key)) {
            SftpConnectionPool::forget(SftpConnectionPool::key($key, $data['host'], (int) $data['port'], $data['username']));
        }
    }

    private function deleteLog(Request $request): void
    {
        $id = $request->session()->get(EnsureSftpConnected::SESSION_KEY.'.log');
        if (is_string($id)) {
            TransferLog::forId($id)->delete();
        }
    }
}
