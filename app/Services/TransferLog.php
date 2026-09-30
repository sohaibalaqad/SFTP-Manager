<?php

namespace App\Services;

use App\Exceptions\SftpException;
use App\Http\Middleware\EnsureSftpConnected;
use Illuminate\Http\Request;

/**
 * Temporary, per-connection log of transfers between the server and the user's device
 * (uploads, downloads and editor saves).
 *
 * Stored as an append-only JSON-lines file (appends are atomic with LOCK_EX, so concurrent
 * requests never overwrite each other). The file is deleted on disconnect, and files of
 * expired sessions are pruned. It never contains credentials — only file names, sizes and results.
 */
class TransferLog
{
    private const MAX_BYTES = 512 * 1024;

    private const KEEP_ENTRIES = 300;

    private function __construct(private readonly string $file) {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function forId(string $id): self
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new SftpException('سجل النقل غير متاح.', 401);
        }

        return new self(self::dir().'/'.$id.'.jsonl');
    }

    public static function fromRequest(Request $request): self
    {
        $id = $request->session()->get(EnsureSftpConnected::SESSION_KEY.'.log');
        if (! is_string($id)) {
            throw new SftpException('انتهت جلسة الاتصال. يرجى الاتصال مجددًا.', 401);
        }

        return self::forId($id);
    }

    /** Delete logs of sessions that expired without an explicit disconnect. */
    public static function prune(): void
    {
        $cutoff = time() - max(60, (int) config('session.lifetime') * 60) - 3600;
        foreach (array_merge(glob(self::dir().'/*.jsonl') ?: [], glob(self::dir().'/*.progress') ?: []) as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Record an event. Events sharing an id describe the same transfer (started → success/failed/canceled).
     *
     * @param  array{id: string, type: string, name: string, path?: string, size?: ?int, status: string, message?: string}  $entry
     */
    public function add(array $entry): void
    {
        $entry['time'] = round(microtime(true), 3);
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        @mkdir(dirname($this->file), 0700, true);
        $new = ! is_file($this->file);
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($new) {
            @chmod($this->file, 0600);
        }

        clearstatcache(true, $this->file);
        if (filesize($this->file) > self::MAX_BYTES) {
            $lines = array_slice(file($this->file) ?: [], -self::KEEP_ENTRIES);
            file_put_contents($this->file, implode('', $lines), LOCK_EX);
        }
    }

    /**
     * Live byte count of a running transfer (one small file per transfer, overwritten, never appended).
     */
    public function progress(string $id, int $bytes): void
    {
        @file_put_contents($this->progressFile($id), json_encode(['id' => $id, 'bytes' => $bytes]), LOCK_EX);
    }

    public function clearProgress(string $id): void
    {
        @unlink($this->progressFile($id));
    }

    /**
     * All transfers, newest first, with events of the same id merged into one row.
     */
    public function entries(): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $rows = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $e = json_decode($line, true);
            if (! is_array($e) || ! isset($e['id'])) {
                continue;
            }
            $id = $e['id'];
            if (! isset($rows[$id])) {
                $rows[$id] = $e + ['started' => $e['time'], 'finished' => null];
            } else {
                $rows[$id] = array_merge($rows[$id], array_filter($e, fn ($v) => $v !== null));
            }
            if ($e['status'] !== 'started') {
                $rows[$id]['finished'] = $e['time'];
            }
        }

        foreach (glob($this->base().'.*.progress') ?: [] as $file) {
            $p = json_decode((string) @file_get_contents($file), true);
            if (isset($p['id'], $rows[$p['id']]) && $rows[$p['id']]['status'] === 'started') {
                $rows[$p['id']]['transferred'] = (int) $p['bytes'];
            }
        }

        foreach ($rows as &$row) {
            $row['duration'] = $row['finished'] !== null ? round($row['finished'] - $row['started'], 2) : null;
            unset($row['time']);
        }

        return array_reverse(array_values($rows));
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            file_put_contents($this->file, '', LOCK_EX);
        }
    }

    public function delete(): void
    {
        @unlink($this->file);
        array_map('unlink', glob($this->base().'.*.progress') ?: []);
    }

    private function base(): string
    {
        return substr($this->file, 0, -strlen('.jsonl'));
    }

    private function progressFile(string $id): string
    {
        return $this->base().'.'.md5($id).'.progress';
    }

    private static function dir(): string
    {
        return storage_path('app/transfer-logs');
    }
}
