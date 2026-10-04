<?php

namespace App\Http\Controllers;

use App\Exceptions\SftpException;
use App\Http\Middleware\EnsureSftpConnected;
use App\Services\ArchiveService;
use App\Services\SftpService;
use App\Services\TransferLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Archive operations: list contents, extract, compress to ZIP, download as ZIP and download a
 * single entry. Work happens in a per-connection scratch folder; long operations report their
 * phase and progress through the transfer log (polled by the UI).
 */
class ArchiveController extends Controller
{
    /** Number of downloaded archives kept for reuse (opening then extracting downloads once). */
    private const CACHE_KEEP = 2;

    public function __construct(private readonly ArchiveService $archives) {}

    public function list(Request $request, SftpService $sftp): JsonResponse
    {
        $path = (string) $request->query('path', '');
        $format = ArchiveService::requireFormat($path);
        set_time_limit(0);

        $file = $this->localCopy($request, $sftp, $path);

        return response()->json(['format' => $format] + $this->archives->entries($file, $format));
    }

    public function extract(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'mode' => ['required', 'in:here,folder'],
            'conflict' => ['nullable', 'in:overwrite,skip'],
            'tid' => ['nullable', 'regex:/^[a-f0-9]{8,40}$/'],
        ]);
        set_time_limit(0);

        $path = trim($data['path'], '/');
        $format = ArchiveService::requireFormat($path);
        $parent = str_contains($path, '/') ? dirname($path) : '';
        $log = TransferLog::fromRequest($request);
        $id = 'ar-'.($data['tid'] ?? TransferLog::newId());

        $dest = $parent;
        if ($data['mode'] === 'folder') {
            $folder = $sftp->freeName($parent, ArchiveService::baseName(basename($path)), true);
            $sftp->makeDirs($parent, $folder);
            $dest = ltrim($parent.'/'.$folder, '/');
        }

        $entry = [
            'id' => $id, 'type' => 'extract', 'name' => basename($path), 'path' => $path,
            'from' => $this->remote($request, $path), 'to' => $this->remote($request, $dest),
        ];
        $log->add($entry + ['status' => 'started']);

        try {
            $info = $sftp->fileInfo($path);
            $file = $this->localCopy($request, $sftp, $path, fn (int $bytes) => $log->progress($id, $bytes, $info['size'], 'download'));

            $total = $this->archives->entries($file, $format)['size'];
            $log->progress($id, 0, $total, 'extract');

            $overwrite = ($data['conflict'] ?? 'overwrite') === 'overwrite';
            $existing = 0;
            $work = ArchiveService::workspace($this->logId($request));
            $stats = $this->archives->extract(
                $file, $format, $work,
                fn (string $dir) => $sftp->makeDirs($dest, $dir),
                function (string $rel, string $tmp) use ($sftp, $dest, $overwrite, &$existing) {
                    if (str_contains($rel, '/')) {
                        $sftp->makeDirs($dest, dirname($rel));
                    }
                    if (! $sftp->putFile($dest, $rel, $tmp, $overwrite)) {
                        $existing++;
                    }
                },
                fn (int $bytes) => $log->progress($id, $bytes, $total, 'extract'),
            );
        } catch (Throwable $e) {
            $log->clearProgress($id);
            $log->add($entry + ['status' => 'failed', 'message' => $e instanceof SftpException ? $e->getMessage() : 'تعذر استخراج الأرشيف.']);
            throw $e;
        }

        $log->clearProgress($id);
        $log->add($entry + ['status' => 'success', 'size' => $stats['bytes']]);

        return response()->json([
            'message' => 'تم استخراج الأرشيف.',
            'target' => $dest,
            'files' => $stats['files'] - $existing,
            'dirs' => $stats['dirs'],
            'existing' => $existing,
            'unsafe' => $stats['skipped'],
            'bytes' => $stats['bytes'],
        ]);
    }

    public function compress(Request $request, SftpService $sftp): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string'],
            'dest' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:200', 'not_regex:#[/\\\\\x00]#'],
            'tid' => ['nullable', 'regex:/^[a-f0-9]{8,40}$/'],
        ]);
        set_time_limit(0);

        $dest = trim((string) ($data['dest'] ?? ''), '/');
        $name = str_ends_with(strtolower($data['name']), '.zip') ? $data['name'] : $data['name'].'.zip';
        $log = TransferLog::fromRequest($request);
        $id = 'ar-'.($data['tid'] ?? TransferLog::newId());
        $entry = [
            'id' => $id, 'type' => 'compress', 'name' => $name, 'path' => ltrim($dest.'/'.$name, '/'),
            'from' => $this->remote($request, count($data['paths']) === 1 ? $data['paths'][0] : $dest),
            'to' => $this->remote($request, ltrim($dest.'/'.$name, '/')),
        ];
        $log->add($entry + ['status' => 'started']);

        $work = ArchiveService::workspace($this->logId($request)).'/compress-'.TransferLog::newId();
        try {
            $zip = $this->buildZip($sftp, $data['paths'], $work, $log, $id);
            $name = $sftp->freeName($dest, $name, false);
            $size = (int) filesize($zip);
            $log->progress($id, 0, $size, 'upload');
            $sftp->putFile($dest, $name, $zip, true, fn (int $sent) => $log->progress($id, $sent, $size, 'upload'));
        } catch (Throwable $e) {
            $log->clearProgress($id);
            $log->add($entry + ['status' => 'failed', 'message' => $e instanceof SftpException ? $e->getMessage() : 'تعذر إنشاء الملف المضغوط.']);
            throw $e;
        } finally {
            File::deleteDirectory($work);
        }

        $log->clearProgress($id);
        $log->add($entry + ['status' => 'success', 'name' => $name, 'size' => $size, 'path' => ltrim($dest.'/'.$name, '/'), 'to' => $this->remote($request, ltrim($dest.'/'.$name, '/'))]);

        return response()->json(['message' => "تم إنشاء {$name}.", 'name' => $name, 'bytes' => $size]);
    }

    /** Download files/folders as one ZIP (also the way to download a whole folder). */
    public function downloadZip(Request $request, SftpService $sftp): StreamedResponse
    {
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string'],
            'tid' => ['nullable', 'regex:/^[a-f0-9]{8,40}$/'],
        ]);
        set_time_limit(0);

        $paths = $data['paths'];
        $first = trim($paths[0], '/');
        $parent = str_contains($first, '/') ? basename(dirname($first)) : '';
        $name = (count($paths) === 1 ? basename($first) : ($parent ?: 'files')).'.zip';

        $log = TransferLog::fromRequest($request);
        $id = 'dl-'.($data['tid'] ?? TransferLog::newId());
        $entry = [
            'id' => $id, 'type' => 'download', 'name' => $name, 'path' => $first,
            'from' => $this->remote($request, count($paths) === 1 ? $first : dirname($first)),
            'to' => ['side' => 'device', 'path' => $name, 'note' => 'downloads'],
        ];
        $log->add($entry + ['status' => 'started']);

        $work = ArchiveService::workspace($this->logId($request)).'/zip-'.TransferLog::newId();
        try {
            $zip = $this->buildZip($sftp, $paths, $work, $log, $id);
        } catch (Throwable $e) {
            File::deleteDirectory($work);
            $log->clearProgress($id);
            $log->add($entry + ['status' => 'failed', 'message' => $e instanceof SftpException ? $e->getMessage() : 'تعذر إنشاء الملف المضغوط.']);
            throw $e;
        }

        $size = (int) filesize($zip);

        return new StreamedResponse(function () use ($zip, $work, $size, $log, $id, $entry) {
            ignore_user_abort(true);
            $sent = 0;
            $in = fopen($zip, 'rb');
            try {
                while (! feof($in) && ! connection_aborted()) {
                    echo fread($in, 1048576);
                    flush();
                    $sent = ftell($in);
                    $log->progress($id, $sent, $size, 'send');
                }
            } finally {
                fclose($in);
                File::deleteDirectory($work);
                $log->clearProgress($id);
                $log->add($entry + ($sent >= $size
                    ? ['status' => 'success', 'size' => $size]
                    : ['status' => 'canceled', 'message' => 'تم إلغاء التنزيل من المتصفح.']));
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $size,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name) ?: 'files.zip'),
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Download one file from inside an archive. */
    public function entry(Request $request, SftpService $sftp): StreamedResponse
    {
        $path = (string) $request->query('path', '');
        $entryPath = ArchiveService::safePath((string) $request->query('entry', ''));
        $format = ArchiveService::requireFormat($path);
        if ($entryPath === null || $entryPath === '') {
            throw new SftpException('الملف غير موجود في الأرشيف.', 404);
        }
        set_time_limit(0);

        $file = $this->localCopy($request, $sftp, $path);
        $out = ArchiveService::workspace($this->logId($request)).'/entry-'.TransferLog::newId();
        if (! $this->archives->extractEntry($file, $format, $entryPath, $out)) {
            throw new SftpException('الملف غير موجود في الأرشيف.', 404);
        }
        $name = basename($entryPath);

        return new StreamedResponse(function () use ($out) {
            readfile($out);
            @unlink($out);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($out),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name) ?: 'file'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Download the selected server files into $work and zip them. Returns the ZIP path.
     *
     * @param  list<string>  $paths
     */
    private function buildZip(SftpService $sftp, array $paths, string $work, TransferLog $log, string $id): string
    {
        $collected = $sftp->collect($paths, ArchiveService::MAX_COMPRESS_BYTES);
        File::ensureDirectoryExists($work.'/src');

        $log->progress($id, 0, $collected['bytes'], 'download');
        $sftp->fetchTree($collected['items'], $work.'/src', fn (int $bytes) => $log->progress($id, $bytes, $collected['bytes'], 'download'));

        $zip = $work.'/archive.zip';
        $log->progress($id, 0, 100, 'compress');
        $this->archives->createZip($work.'/src', $zip, fn (float $rate) => $log->progress($id, (int) round($rate * 100), 100, 'compress'));

        return $zip;
    }

    /**
     * Local copy of a remote archive, reused while its size and date are unchanged
     * (opening and then extracting an archive downloads it only once).
     *
     * @param  callable(int): void|null  $progress
     */
    private function localCopy(Request $request, SftpService $sftp, string $rel, ?callable $progress = null): string
    {
        $info = $sftp->fileInfo($rel);
        if ($info['dir']) {
            throw new SftpException('هذا مجلد وليس أرشيفًا.', 422);
        }

        $dir = ArchiveService::workspace($this->logId($request)).'/cache';
        File::ensureDirectoryExists($dir);
        $key = sha1($rel);
        $file = "{$dir}/{$key}.archive";
        $meta = "{$dir}/{$key}.json";

        $cached = is_file($file) && is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (is_array($cached) && $cached['size'] === $info['size'] && $cached['mtime'] === $info['mtime']) {
            touch($file);

            return $file;
        }

        $sftp->fetch($rel, $file.'.part', $progress);
        rename($file.'.part', $file);
        file_put_contents($meta, json_encode(['size' => $info['size'], 'mtime' => $info['mtime']]));

        // Keep only the most recently used archives.
        $all = glob($dir.'/*.archive') ?: [];
        usort($all, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $all = array_values(array_filter($all, fn ($f) => $f !== $file)); // never the one just downloaded
        foreach (array_slice($all, self::CACHE_KEEP - 1) as $old) {
            File::delete([$old, substr($old, 0, -strlen('.archive')).'.json']);
        }

        return $file;
    }

    private function logId(Request $request): string
    {
        return (string) $request->session()->get(EnsureSftpConnected::SESSION_KEY.'.log');
    }

    /** @return array{side: string, path: string} */
    private function remote(Request $request, string $rel): array
    {
        $root = (string) $request->session()->get(EnsureSftpConnected::SESSION_KEY.'.root', '/');
        $rel = trim($rel, '/');

        return ['side' => 'server', 'path' => $rel === '' ? $root : rtrim($root, '/').'/'.$rel];
    }
}
