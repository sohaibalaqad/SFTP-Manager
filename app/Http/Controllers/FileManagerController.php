<?php

namespace App\Http\Controllers;

use App\Exceptions\SftpException;
use App\Http\Middleware\EnsureSftpConnected;
use App\Services\SftpService;
use App\Services\TransferLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FileManagerController extends Controller
{
    /** Types that may be shown inline (preview) instead of downloaded. */
    private const PREVIEW_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $server = $request->session()->get(EnsureSftpConnected::SESSION_KEY);
        if (! is_array($server) || ! $request->hasCookie(EnsureSftpConnected::COOKIE_KEY)) {
            return redirect()->route('home')->with('error', 'انتهت جلسة الاتصال. يرجى الاتصال مجددًا.');
        }

        return view('files', [
            'server' => [
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => $server['port'],
                'username' => $server['username'],
                'root' => $server['root'],
                'fingerprint' => $server['fingerprint'],
            ],
            'chunkSize' => $this->chunkSize(),
            'maxEditSize' => SftpService::MAX_EDIT_SIZE,
        ]);
    }

    public function list(Request $request, SftpService $sftp): JsonResponse
    {
        $path = (string) $request->query('path', '');

        return response()->json(['path' => $path, 'items' => $this->withOwners($request, $sftp, $sftp->listDirectory($path))]);
    }

    public function stat(Request $request, SftpService $sftp): JsonResponse
    {
        $item = $this->withOwners($request, $sftp, [$sftp->stat((string) $request->query('path', ''))])[0];
        if ($item['dir']) {
            $item['children'] = $sftp->countChildren($item['path']);
        }

        return response()->json($item);
    }

    public function search(Request $request, SftpService $sftp): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:255']]);
        set_time_limit(120);

        $result = $sftp->search((string) $request->query('path', ''), (string) $request->query('q'));
        $result['items'] = $this->withOwners($request, $sftp, $result['items']);

        return response()->json($result);
    }

    /**
     * Add owner/group names to items. The ID → name map is read once and kept in the session for
     * 10 minutes (it is discarded with the session on disconnect).
     */
    private function withOwners(Request $request, SftpService $sftp, array $items): array
    {
        $key = EnsureSftpConnected::SESSION_KEY.'.accounts';
        $accounts = $request->session()->get($key);
        if (! is_array($accounts) || ($accounts['at'] ?? 0) < time() - 600) {
            $accounts = $sftp->accountNames() + ['at' => time()];
            $request->session()->put($key, $accounts);
        }

        foreach ($items as &$item) {
            $item['owner'] = $item['uid'] !== null ? ($accounts['users'][$item['uid']] ?? null) : null;
            $item['group'] = $item['gid'] !== null ? ($accounts['groups'][$item['gid']] ?? null) : null;
        }

        return $items;
    }

    /** Recursive listing used to compare a local folder with this server folder. */
    public function tree(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'dirs' => ['nullable', 'array', 'max:50000'],
            'dirs.*' => ['string', 'max:4096'],
        ]);
        set_time_limit(300);

        return response()->json($sftp->tree((string) ($data['path'] ?? ''), $data['dirs'] ?? []));
    }

    /** Largest file whose content is compared by hash during a folder comparison. */
    public const MAX_HASH_BYTES = 50 * 1024 * 1024;

    /**
     * Content hashes (SHA-256) of some files, for the folder comparison. Only used for files whose size is
     * the same but whose date differs; nothing is stored.
     */
    public function hashes(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'files' => ['required', 'array', 'max:500'],
            'files.*' => ['string', 'max:4096'],
        ]);
        set_time_limit(300);

        $base = trim((string) ($data['path'] ?? ''), '/');
        $hashes = [];
        foreach ($data['files'] as $rel) {
            try {
                $hashes[$rel] = $sftp->hashFile($base === '' ? $rel : $base.'/'.$rel, self::MAX_HASH_BYTES);
            } catch (SftpException $e) {
                if ($e->status === 503) {
                    throw $e;
                }
                $hashes[$rel] = null; // unreadable: the UI falls back to size + date for this file
            }
        }

        return response()->json(['hashes' => $hashes]);
    }

    public function read(Request $request, SftpService $sftp): JsonResponse
    {
        return response()->json($sftp->readFile((string) $request->query('path', '')));
    }

    public function write(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'content' => ['nullable', 'string'],
            'mtime' => ['nullable', 'integer'],
            'size' => ['nullable', 'integer', 'min:0'],
            'force' => ['boolean'],
        ]);

        $content = (string) ($data['content'] ?? '');
        $target = $this->remote($request, $data['path']);
        $entry = [
            'id' => 'save-'.TransferLog::newId(), 'type' => 'save', 'name' => basename($data['path']), 'path' => $data['path'], 'size' => strlen($content),
            'from' => ['side' => 'editor', 'path' => $target['path']], 'to' => $target,
        ];

        $force = $request->boolean('force');
        try {
            $saved = $sftp->writeFile($data['path'], $content, $force ? null : ($data['mtime'] ?? null), $force ? null : ($data['size'] ?? null));
        } catch (SftpException $e) {
            if ($e->status !== 409) { // 409 = "changed on server", the user is asked and may retry
                TransferLog::fromRequest($request)->add($entry + ['status' => 'failed', 'message' => $e->getMessage()]);
            }
            throw $e;
        }
        TransferLog::fromRequest($request)->add($entry + ['status' => 'success']);

        return response()->json(['message' => 'تم حفظ الملف على السيرفر.'] + $saved);
    }

    public function createDirectory(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate(['path' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:255']]);
        $sftp->createDirectory((string) ($data['path'] ?? ''), $data['name']);

        return response()->json($this->withListing($request, $sftp, ['message' => 'تم إنشاء المجلد.']));
    }

    public function createFile(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate(['path' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:255']]);
        $sftp->createFile((string) ($data['path'] ?? ''), $data['name']);

        return response()->json($this->withListing($request, $sftp, ['message' => 'تم إنشاء الملف.']));
    }

    public function rename(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string'], 'name' => ['required', 'string', 'max:255']]);
        $sftp->rename($data['path'], $data['name']);

        return response()->json($this->withListing($request, $sftp, ['message' => 'تمت إعادة التسمية.']));
    }

    public function delete(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate(['paths' => ['required', 'array', 'min:1'], 'paths.*' => ['required', 'string']]);
        set_time_limit(0);

        return $this->batch($request, $sftp, $data['paths'], fn ($path) => $sftp->delete($path), 'تم الحذف.');
    }

    /** Paste = copy or move a list of items into a destination folder (remote-to-remote). */
    public function paste(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:copy,cut'],
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
            'dest' => ['nullable', 'string'],
        ]);
        set_time_limit(0);
        $dest = (string) ($data['dest'] ?? '');
        $skipped = 0;

        return $this->batch($request, $sftp, $data['paths'], function ($path) use ($sftp, $data, $dest, &$skipped) {
            if ($data['mode'] === 'cut') {
                $sftp->move($path, $dest);
            } else {
                $skipped += $sftp->copy($path, $dest)['skipped'];
            }
        }, $data['mode'] === 'cut' ? 'تم النقل.' : 'تم النسخ.', function () use (&$skipped) {
            return $skipped ? ['warning' => "تم تخطي {$skipped} من الروابط الرمزية أو الملفات الخاصة."] : [];
        });
    }

    public function upload(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'uploadId' => ['required', 'string'],
            'offset' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:0'],
            'overwrite' => ['boolean'],
            'source' => ['nullable', 'string', 'max:1024'],
            'chunk' => ['nullable', 'file'],
        ]);
        set_time_limit(0);

        $chunk = $request->file('chunk');
        if ((int) $data['total'] > 0 && (! $chunk || ! $chunk->isValid())) {
            throw new SftpException('تعذر رفع الملف.', 422);
        }

        $log = TransferLog::fromRequest($request);
        $entry = $this->uploadEntry($request, $data);
        if ((int) $data['offset'] === 0) {
            $log->add($entry + ['status' => 'started']);
        }

        try {
            $done = $sftp->upload(
                (string) ($data['path'] ?? ''),
                $data['name'],
                $data['uploadId'],
                (int) $data['offset'],
                (int) $data['total'],
                $chunk?->getRealPath() ?: null,
                $request->boolean('overwrite'),
            );
        } catch (SftpException $e) {
            $log->add($entry + ['status' => 'failed', 'message' => $e->getMessage()]);
            throw $e;
        }

        if ($done) {
            $log->add($entry + ['status' => 'success']);
        }

        return response()->json(['done' => $done]);
    }

    public function abortUpload(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
            'total' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'in:canceled,failed,paused'],
            'source' => ['nullable', 'string', 'max:1024'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = $data['reason'] ?? 'canceled';
        if ($reason !== 'paused') { // a paused upload keeps its .part file so it can resume later
            $sftp->abortUpload((string) ($data['path'] ?? ''), $data['name'], $data['uploadId']);
        }

        // Records failures the server never saw (e.g. the browser lost the network) and cancellations.
        TransferLog::fromRequest($request)->add($this->uploadEntry($request, $data) + array_filter([
            'status' => $reason,
            'message' => match ($reason) {
                'failed' => $data['message'] ?? 'تعذر رفع الملف.',
                'paused' => 'متوقف مؤقتًا بسبب انقطاع الاتصال — يمكن استئنافه.',
                default => null,
            },
        ]));

        return response()->json(['ok' => true]);
    }

    /** How much of an interrupted upload is already on the server (to resume from there). */
    public function uploadStatus(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate(['path' => ['nullable', 'string'], 'name' => ['required', 'string'], 'uploadId' => ['required', 'string']]);

        return response()->json(['size' => $sftp->uploadStatus((string) ($data['path'] ?? ''), $data['name'], $data['uploadId'])]);
    }

    public function download(Request $request, SftpService $sftp): StreamedResponse
    {
        $path = (string) $request->query('path', '');
        $inline = $request->boolean('inline');

        // Previews are not transfers to the device, so they are not logged.
        $log = $inline ? null : TransferLog::fromRequest($request);
        $tid = (string) $request->query('tid', '');
        $entry = [
            'id' => 'dl-'.(preg_match('/^[a-f0-9]{8,40}$/', $tid) ? $tid : TransferLog::newId()),
            'type' => 'download',
            'name' => basename($path),
            'path' => $path,
            'from' => $this->remote($request, $path),
            // Browsers don't reveal local paths: the file goes to the browser's download folder,
            // unless the UI saves it into a folder chosen in the local panel (it then tells us which).
            'to' => $request->filled('dest')
                ? ['side' => 'device', 'path' => mb_substr((string) $request->query('dest'), 0, 1024), 'note' => 'local']
                : ['side' => 'device', 'path' => basename($path), 'note' => 'downloads'],
        ];

        try {
            $info = $sftp->download($path);
        } catch (SftpException $e) {
            $log?->add($entry + ['status' => 'failed', 'message' => $e->getMessage()]);
            throw $e;
        }
        $entry['size'] = $info['size'];
        $log?->add($entry + ['status' => 'started']);

        $ext = strtolower(pathinfo($info['name'], PATHINFO_EXTENSION));
        $inline = $inline && isset(self::PREVIEW_TYPES[$ext]);

        $headers = [
            'Content-Type' => $inline ? self::PREVIEW_TYPES[$ext] : 'application/octet-stream',
            'Content-Length' => (string) $info['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $info['name'],
                preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $info['name']) ?: 'download',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ];
        if ($ext === 'svg') {
            // SVG can contain scripts: render it fully sandboxed.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }

        // Keep the SFTP connection open until the body has been streamed.
        $request->attributes->set('sftp_keep_open', true);

        return new StreamedResponse(function () use ($info, $sftp, $log, $entry) {
            $lastProgress = 0.0;
            set_time_limit(0);
            ignore_user_abort(true); // keep running so the result can be logged if the user cancels
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $sent = 0;
            $status = 'failed';
            $message = 'تعذر تنزيل الملف.';
            try {
                $ok = ($info['stream'])(function (string $data) use (&$sent, &$lastProgress, $log, $entry) {
                    if (connection_aborted()) {
                        throw new \RuntimeException('client-aborted');
                    }
                    echo $data;
                    flush();
                    $sent += strlen($data);
                    // Bytes sent so far, for the progress bar in the UI (at most twice per second).
                    if ($log && microtime(true) - $lastProgress >= 0.5) {
                        $log->progress($entry['id'], $sent);
                        $lastProgress = microtime(true);
                    }
                });
                if (connection_aborted()) {
                    [$status, $message] = ['canceled', 'تم إلغاء التنزيل من المتصفح.'];
                } elseif ($ok !== false && $sent === $info['size']) {
                    $status = 'success';
                } else {
                    $message = 'انقطع التنزيل قبل اكتماله.';
                }
            } catch (Throwable $e) {
                if ($e->getMessage() === 'client-aborted' || connection_aborted()) {
                    [$status, $message] = ['canceled', 'تم إلغاء التنزيل من المتصفح.'];
                } else {
                    report($e);
                    $message = SftpService::MSG_CONNECTION_LOST;
                }
            } finally {
                // A finished transfer leaves the connection clean (back to the pool); an interrupted one does not.
                $status === 'success' ? $sftp->release() : $sftp->disconnect();
                $log?->clearProgress($entry['id']);
                $log?->add($entry + array_filter(['status' => $status, 'message' => $status === 'success' ? null : $message]));
            }
        }, 200, $headers);
    }

    /** The temporary transfer log of this connection (does not open an SFTP connection). */
    public function log(Request $request): JsonResponse
    {
        return response()->json(['entries' => TransferLog::fromRequest($request)->entries()]);
    }

    public function clearLog(Request $request): JsonResponse
    {
        TransferLog::fromRequest($request)->clear();

        return response()->json(['message' => 'تم مسح سجل النقل.']);
    }

    private function uploadEntry(Request $request, array $data): array
    {
        $dir = trim((string) ($data['path'] ?? ''), '/');
        $rel = $dir === '' ? $data['name'] : $dir.'/'.$data['name'];

        return [
            'id' => 'up-'.$data['uploadId'],
            'type' => 'upload',
            'name' => $data['name'],
            'path' => $rel,
            'size' => isset($data['total']) ? (int) $data['total'] : null,
            // The browser only exposes the file name (or its path inside a dropped folder), never the full local path.
            'from' => ['side' => 'device', 'path' => (string) ($data['source'] ?? '') ?: $data['name']],
            'to' => $this->remote($request, $rel),
        ];
    }

    /** Absolute server path of a root-relative path, for display in the transfer log. */
    private function remote(Request $request, string $rel): array
    {
        $root = (string) $request->session()->get(EnsureSftpConnected::SESSION_KEY.'.root', '/');
        $rel = trim($rel, '/');

        return ['side' => 'server', 'path' => $rel === '' ? $root : rtrim($root, '/').'/'.$rel];
    }

    /**
     * Run an operation for each path, collecting per-item errors instead of stopping at the first one.
     */
    private function batch(Request $request, SftpService $sftp, array $paths, callable $fn, string $success, ?callable $extra = null): JsonResponse
    {
        $errors = [];
        foreach ($paths as $path) {
            try {
                $fn((string) $path);
            } catch (SftpException $e) {
                $errors[] = ['path' => $path, 'message' => $e->getMessage()];
                if ($e->status === 503) {
                    break; // connection lost: no point continuing
                }
            } catch (Throwable $e) {
                report($e);
                $errors[] = ['path' => $path, 'message' => 'حدث خطأ غير متوقع.'];
            }
        }

        $done = count($paths) - count($errors);

        return response()->json($this->withListing($request, $sftp, array_merge([
            'message' => $errors ? ($done ? 'اكتملت العملية جزئيًا.' : $errors[0]['message']) : $success,
            'done' => $done,
            'errors' => $errors,
        ], $extra ? $extra() : [])), $errors && ! $done ? 422 : 200);
    }

    /**
     * When the UI sends `list` (the folder it is showing), include that folder's fresh listing in the
     * response. This saves a second request — and a whole new SSH connection — after every change.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withListing(Request $request, SftpService $sftp, array $payload): array
    {
        if (! $request->exists('list')) {
            return $payload;
        }

        $dir = (string) $request->input('list', '');
        try {
            $payload['listing'] = ['path' => $dir, 'items' => $this->withOwners($request, $sftp, $sftp->listDirectory($dir))];
        } catch (Throwable) {
            // The change itself succeeded; the UI will simply reload the folder.
        }

        return $payload;
    }

    /** Upload chunk size that fits within PHP's upload/post limits (max 16 MB). */
    private function chunkSize(): int
    {
        $toBytes = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $limits = array_filter([
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')) - 64 * 1024,
        ], fn ($v) => $v > 0);

        return max(256 * 1024, min([16 * 1024 * 1024, ...$limits]));
    }
}
