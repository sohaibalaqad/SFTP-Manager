<?php

namespace App\Services;

use App\Exceptions\SftpException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * Reads, extracts and creates archives on this machine (the SFTP protocol has no way to run
 * `unzip`/`tar` on the server, so archives are processed locally and transferred).
 *
 * Every entry name is sanitised: absolute paths, ".." segments and symlinks are skipped, so an
 * archive can never write outside its target folder. Total size and entry count are capped to
 * protect against archive bombs.
 */
class ArchiveService
{
    public const MAX_ENTRIES = 100000;

    public const MAX_EXTRACT_BYTES = 10 * 1024 ** 3;

    public const MAX_COMPRESS_BYTES = 5 * 1024 ** 3;

    /**
     * Archive format of a file name: zip, tar, tar.gz or tar.bz2.
     *
     * @throws SftpException for archive types that cannot be opened (rar, 7z, …)
     */
    public static function format(string $name): ?string
    {
        $lower = strtolower($name);

        return match (true) {
            str_ends_with($lower, '.zip') => 'zip',
            str_ends_with($lower, '.tar') => 'tar',
            str_ends_with($lower, '.tar.gz'), str_ends_with($lower, '.tgz') => 'tar.gz',
            str_ends_with($lower, '.tar.bz2'), str_ends_with($lower, '.tbz2'), str_ends_with($lower, '.tbz') => 'tar.bz2',
            default => null,
        };
    }

    public static function requireFormat(string $name): string
    {
        $format = self::format($name);
        if ($format === null) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            throw new SftpException(
                in_array($ext, ['rar', '7z', 'xz', 'zst'], true)
                    ? "صيغة .{$ext} غير مدعومة. الصيغ المدعومة: ZIP و TAR و TAR.GZ و TAR.BZ2."
                    : 'هذا الملف ليس أرشيفًا مدعومًا.',
                422,
            );
        }

        return $format;
    }

    /** Name without the archive extension: "site-backup.tar.gz" → "site-backup". */
    public static function baseName(string $name): string
    {
        return preg_replace('/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tbz)$/i', '', $name) ?: $name;
    }

    /**
     * Safe relative path for an entry; '' for the archive's own root entry ("./"), which is
     * ignored silently; null when the entry is unsafe (contains "..") or macOS resource-fork junk.
     */
    public static function safePath(string $name): ?string
    {
        if (str_contains($name, "\0")) {
            return null;
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $name)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }
        if (! $parts) {
            return '';
        }
        if ($parts[0] === '__MACOSX') {
            return null;
        }
        if (preg_match('/^[A-Za-z]:$/', $parts[0])) {
            return null; // "C:/..." in archives made on Windows
        }

        return implode('/', $parts);
    }

    /**
     * List the entries of a local archive.
     *
     * @return array{entries: list<array{path: string, dir: bool, size: int, mtime: ?int}>, truncated: bool, skipped: int, size: int}
     */
    public function entries(string $file, string $format): array
    {
        $entries = [];
        $skipped = 0;
        $total = 0;
        $truncated = false;

        $this->walk($file, $format, function (array $e) use (&$entries, &$skipped, &$total, &$truncated) {
            if ($e['path'] === '') {
                return true;
            }
            if ($e['path'] === null || $e['link']) {
                $skipped++;

                return true;
            }
            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;

                return false;
            }
            $entries[] = ['path' => $e['path'], 'dir' => $e['dir'], 'size' => $e['size'], 'mtime' => $e['mtime']];
            $total += $e['size'];

            return true;
        });

        return ['entries' => $entries, 'truncated' => $truncated, 'skipped' => $skipped, 'size' => $total];
    }

    /**
     * Extract entries one at a time: each file is written to a temporary file, handed to $onFile
     * (which uploads it) and deleted. Folders are reported through $onDir.
     *
     * @param  callable(string): void  $onDir
     * @param  callable(string, string, int): void  $onFile  (relative path, temp file, size)
     * @param  callable(int): void|null  $progress  bytes extracted so far
     * @return array{files: int, dirs: int, skipped: int, bytes: int}
     */
    public function extract(string $file, string $format, string $workDir, callable $onDir, callable $onFile, ?callable $progress = null): array
    {
        $stats = ['files' => 0, 'dirs' => 0, 'skipped' => 0, 'bytes' => 0];
        $tmp = $workDir.'/entry.tmp';

        $this->walk($file, $format, function (array $e, callable $writeTo) use (&$stats, $tmp, $onDir, $onFile, $progress) {
            if ($e['path'] === '') {
                return true;
            }
            if ($e['path'] === null || $e['link']) {
                $stats['skipped']++;

                return true;
            }
            if ($stats['files'] + $stats['dirs'] >= self::MAX_ENTRIES) {
                throw new SftpException('الأرشيف يحتوي عددًا كبيرًا جدًا من الملفات.', 422);
            }
            if ($e['dir']) {
                $onDir($e['path']);
                $stats['dirs']++;

                return true;
            }
            if ($stats['bytes'] + $e['size'] > self::MAX_EXTRACT_BYTES) {
                throw new SftpException('حجم الأرشيف بعد الاستخراج أكبر من الحد المسموح (10 GB).', 422);
            }

            $writeTo($tmp);
            $size = (int) filesize($tmp);
            if ($stats['bytes'] + $size > self::MAX_EXTRACT_BYTES) {
                @unlink($tmp);
                throw new SftpException('حجم الأرشيف بعد الاستخراج أكبر من الحد المسموح (10 GB).', 422);
            }
            try {
                $onFile($e['path'], $tmp, $size);
            } finally {
                @unlink($tmp);
            }
            $stats['files']++;
            $stats['bytes'] += $size;
            if ($progress) {
                $progress($stats['bytes']);
            }

            return true;
        });

        return $stats;
    }

    /** Copy one file out of the archive. Returns false when the entry does not exist. */
    public function extractEntry(string $file, string $format, string $entry, string $toFile): bool
    {
        $found = false;
        $this->walk($file, $format, function (array $e, callable $writeTo) use ($entry, $toFile, &$found) {
            if ($e['path'] === $entry && ! $e['dir'] && ! $e['link']) {
                $writeTo($toFile);
                $found = true;

                return false;
            }

            return true;
        });

        return $found;
    }

    /**
     * Create a ZIP from every file and folder inside $sourceDir.
     *
     * @param  callable(float): void|null  $progress  0..1 while writing
     */
    public function createZip(string $sourceDir, string $zipFile, ?callable $progress = null): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the ZIP file.');
        }

        foreach (Finder::create()->in($sourceDir)->ignoreDotFiles(false)->ignoreVCS(false)->sortByName() as $item) {
            $name = str_replace('\\', '/', $item->getRelativePathname());
            $item->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($item->getPathname(), $name);
        }

        if ($progress && method_exists($zip, 'registerProgressCallback')) {
            $zip->registerProgressCallback(0.02, fn (float $rate) => $progress($rate));
        }
        if (! $zip->close()) {
            throw new RuntimeException('Unable to write the ZIP file.');
        }
    }

    /** Per-connection scratch folder for archive work (deleted on disconnect). */
    public static function workspace(string $logId): string
    {
        $dir = storage_path('app/archive-work/'.$logId);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public static function deleteWorkspace(string $logId): void
    {
        File::deleteDirectory(storage_path('app/archive-work/'.$logId));
    }

    /** Remove workspaces of sessions that ended without a disconnect. */
    public static function prune(int $olderThanSeconds): void
    {
        $root = storage_path('app/archive-work');
        if (! is_dir($root)) {
            return;
        }
        foreach (File::directories($root) as $dir) {
            if (@filemtime($dir) < time() - $olderThanSeconds) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Visit entries of a ZIP or TAR archive with normalised fields.
     *
     * @param  callable(array{path: ?string, dir: bool, link: bool, size: int, mtime: ?int}, callable(string): void): bool  $visit
     */
    private function walk(string $file, string $format, callable $visit): void
    {
        if ($format === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new SftpException('تعذر فتح الأرشيف. قد يكون الملف تالفًا.', 422);
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = (string) $stat['name'];
                    $zip->getExternalAttributesIndex($i, $os, $attr);
                    $isLink = $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
                    $entry = [
                        'path' => self::safePath($name),
                        'dir' => str_ends_with($name, '/'),
                        'link' => $isLink,
                        'size' => (int) $stat['size'],
                        'mtime' => isset($stat['mtime']) ? (int) $stat['mtime'] : null,
                    ];
                    $writeTo = function (string $to) use ($zip, $i): void {
                        $in = $zip->getStream($zip->getNameIndex($i));
                        if ($in === false) {
                            throw new SftpException('تعذر قراءة ملف من الأرشيف. قد يكون تالفًا أو محميًا بكلمة مرور.', 422);
                        }
                        $out = fopen($to, 'wb');
                        stream_copy_to_stream($in, $out);
                        fclose($out);
                        fclose($in);
                    };
                    if ($visit($entry, $writeTo) === false) {
                        break;
                    }
                }
            } finally {
                $zip->close();
            }

            return;
        }

        try {
            $reader = new TarReader($file, match ($format) {
                'tar.gz' => 'gz',
                'tar.bz2' => 'bz2',
                default => '',
            });
            $reader->each(function (array $e, callable $read) use ($visit) {
                if (! in_array($e['type'], ['0', '5', '2', '1', '7'], true)) {
                    return true; // devices, FIFOs, …
                }

                return $visit([
                    'path' => self::safePath($e['name']),
                    'dir' => $e['type'] === '5',
                    'link' => in_array($e['type'], ['1', '2'], true),
                    'size' => $e['size'],
                    'mtime' => $e['mtime'] ?: null,
                ], fn (string $to) => $read($to));
            });
        } catch (SftpException $e) {
            throw $e;
        } catch (RuntimeException) {
            throw new SftpException('تعذر قراءة الأرشيف. قد يكون الملف تالفًا.', 422);
        }
    }
}
