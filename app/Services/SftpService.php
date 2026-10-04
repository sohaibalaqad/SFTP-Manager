<?php

namespace App\Services;

use App\Exceptions\SftpException;
use Closure;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * Thin wrapper around phpseclib's SFTP client.
 *
 * Every public method takes paths *relative to the connection root* (e.g. "app/Http").
 * They are normalised, symlinks are resolved, and the result must stay inside the root,
 * otherwise the call is rejected. No shell commands are ever executed.
 */
class SftpService
{
    public const MSG_CONNECT_FAILED = 'فشل الاتصال بالسيرفر. تحقق من Host أو Username أو Password.';

    public const MSG_CONNECTION_LOST = 'انقطع الاتصال بالسيرفر. يرجى الاتصال مجددًا.';

    /** Max size (bytes) of a file that can be opened in the editor. */
    public const MAX_EDIT_SIZE = 5 * 1024 * 1024;

    private string $root = '/';

    private ?Closure $releaser = null;

    private function __construct(
        private readonly SFTP $sftp,
        public readonly string $fingerprint,
    ) {}

    /**
     * Open an SFTP connection, verify the server's host key, then log in.
     *
     * The host key is checked BEFORE any credential is sent, so a password or key is never
     * handed to a server that is not the one the user trusted.
     *
     * @param  array{password?: string, key?: string, passphrase?: string}  $credentials
     * @param  string|null  $expectedFingerprint  the fingerprint the user trusts for this host (null = unknown)
     * @param  bool  $verifyHost  when true and no fingerprint is known yet, stop and ask the user to confirm it
     */
    public static function connect(
        string $host,
        int $port,
        string $username,
        #[\SensitiveParameter] array $credentials,
        ?string $expectedFingerprint = null,
        string $failMessage = self::MSG_CONNECT_FAILED,
        bool $verifyHost = false,
    ): self {
        // Load the private key first so a wrong file/passphrase fails fast with a clear message.
        $secret = isset($credentials['key']) ? self::loadPrivateKey($credentials['key'], $credentials['passphrase'] ?? '') : (string) ($credentials['password'] ?? '');

        try {
            $sftp = new SFTP($host, $port, 15);

            $hostKey = $sftp->getServerPublicHostKey();
            if (! is_string($hostKey)) {
                throw new SftpException($failMessage, 502);
            }

            $fingerprint = self::fingerprint($hostKey);
            $hostInfo = ['fingerprint' => $fingerprint, 'algorithm' => strtok($hostKey, ' ') ?: 'unknown'];

            if ($expectedFingerprint === null && $verifyHost) {
                throw new SftpException('أول اتصال بهذا السيرفر: تحقّق من بصمة مفتاحه قبل المتابعة.', 428, ['hostKey' => $hostInfo + ['status' => 'unknown']]);
            }
            if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $fingerprint)) {
                throw new SftpException(
                    $verifyHost
                        ? 'تحذير أمني: بصمة مفتاح السيرفر تغيّرت عن البصمة التي وثقت بها سابقًا.'
                        : 'تغيّر مفتاح السيرفر (Host key) منذ بدء الجلسة. تم إيقاف الاتصال لحمايتك.',
                    409,
                    $verifyHost ? ['hostKey' => $hostInfo + ['status' => 'changed', 'expected' => $expectedFingerprint]] : [],
                );
            }

            if (! $sftp->login($username, $secret)) {
                throw new SftpException(
                    $secret instanceof PrivateKey && $failMessage === self::MSG_CONNECT_FAILED
                        ? 'فشل تسجيل الدخول بمفتاح SSH. تأكد من Username وأن المفتاح العام مضاف إلى authorized_keys لهذا المستخدم.'
                        : $failMessage,
                    401,
                );
            }
        } catch (SftpException $e) {
            throw $e;
        } catch (Throwable) {
            throw new SftpException($failMessage, 502);
        }

        return new self($sftp, $fingerprint);
    }

    private static function loadPrivateKey(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $passphrase): PrivateKey
    {
        try {
            $loaded = PublicKeyLoader::load($key, $passphrase === '' ? false : $passphrase);
        } catch (Throwable) {
            throw new SftpException('تعذر قراءة مفتاح SSH. تأكد من الملف ومن كلمة سر المفتاح (Passphrase) إن وُجدت.', 422);
        }
        if (! $loaded instanceof PrivateKey) {
            throw new SftpException('هذا مفتاح عام (public key). اختر ملف المفتاح الخاص (private key).', 422);
        }

        return $loaded;
    }

    /**
     * Whether the connection can still be used. Cached file info from earlier requests is dropped so a
     * reused connection never shows stale data. With $probePath a cheap request confirms the server
     * still answers (used after the connection sat idle for a while).
     */
    public function isAlive(?string $probePath = null): bool
    {
        try {
            if (! $this->sftp->isConnected()) {
                return false;
            }
            $this->sftp->clearStatCache();

            return $probePath === null || $this->sftp->stat($probePath) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Called when the request is done with the connection (returns it to the pool, when there is one). */
    public function onRelease(?Closure $releaser): void
    {
        $this->releaser = $releaser;
    }

    /** Done with this connection for the current request: hand it back to the pool, or close it. */
    public function release(): void
    {
        $releaser = $this->releaser;
        $this->releaser = null;
        $releaser ? $releaser($this) : $this->disconnect();
    }

    public function disconnect(): void
    {
        try {
            $this->sftp->disconnect();
        } catch (Throwable) {
            // already closed
        }
    }

    /**
     * Resolve and validate the root directory chosen by the user and use it for all further calls.
     * Returns the resolved absolute root.
     */
    public function useRoot(?string $path): string
    {
        $path = trim((string) $path);
        if (str_contains($path, "\0")) {
            throw new SftpException('المسار غير صالح.', 422);
        }
        if ($path === '' || $path[0] !== '/') {
            $home = $this->sftp->pwd() ?: '/';
            $path = rtrim($home, '/').'/'.$path;
        }

        $resolved = $this->resolve($path);
        $stat = $this->sftp->stat($resolved);
        if ($stat === false || ($stat['type'] ?? null) !== NET_SFTP_TYPE_DIRECTORY) {
            throw new SftpException('المسار البعيد (Remote Path) غير موجود أو ليس مجلدًا.', 404);
        }

        return $this->root = $resolved;
    }

    /** Restore a root that was already resolved by useRoot() in an earlier request. */
    public function setResolvedRoot(string $root): void
    {
        $this->root = $root;
    }

    // ---------------------------------------------------------------------
    // Browsing
    // ---------------------------------------------------------------------

    public function listDirectory(string $rel): array
    {
        $rel = $this->normalize($rel);
        $dir = $this->target($rel);

        $raw = $this->call('تعذر فتح المجلد.', fn () => $this->sftp->rawlist($dir));

        $items = [];
        foreach ($raw as $name => $attr) {
            $name = (string) $name;
            if ($name === '.' || $name === '..') {
                continue;
            }
            $items[] = $this->describe($dir.'/'.$name, $this->join($rel, $name), $attr);
        }

        usort($items, fn ($a, $b) => [$b['dir'], mb_strtolower($a['name'])] <=> [$a['dir'], mb_strtolower($b['name'])]);

        return $items;
    }

    /** Basic properties of a file or folder. */
    public function stat(string $rel): array
    {
        $rel = $this->normalize($rel);
        $entry = $this->entry($rel);
        $attr = $this->sftp->lstat($entry);
        if ($attr === false) {
            throw new SftpException('الملف أو المجلد غير موجود.', 404);
        }

        return $this->describe($entry, $rel, $attr);
    }

    public function exists(string $rel): bool
    {
        return $this->sftp->lstat($this->entry($this->normalize($rel))) !== false;
    }

    public function fileSize(string $rel): ?int
    {
        return $this->stat($rel)['size'];
    }

    public function lastModified(string $rel): ?int
    {
        return $this->stat($rel)['mtime'];
    }

    /**
     * Recursive listing for the folder comparison (sync). To keep it fast over slow links it only
     * descends into sub-folders listed in $expand (the folders that also exist locally); other folders
     * are returned as single entries. Symlinked folders are never followed. Paths are relative to $rel.
     *
     * @param  list<string>  $expand
     * @return array{items: list<array{path: string, dir: bool, link: bool, size: ?int, mtime: ?int}>, truncated: bool}
     */
    public function tree(string $rel, array $expand, int $maxItems = 50000): array
    {
        $rel = $this->normalize($rel);
        $expand = array_flip($expand);
        $queue = [[$this->target($rel), '']];
        $items = [];

        while ($queue) {
            [$dir, $sub] = array_shift($queue);
            $raw = $this->call('تعذر قراءة المجلد.', fn () => $this->sftp->rawlist($dir));

            foreach ($raw as $name => $attr) {
                $name = (string) $name;
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (count($items) >= $maxItems) {
                    return ['items' => $items, 'truncated' => true];
                }

                $type = $attr['type'] ?? null;
                $path = $sub === '' ? $name : $sub.'/'.$name;
                $isDir = $type === NET_SFTP_TYPE_DIRECTORY;
                $items[] = [
                    'path' => $path,
                    'dir' => $isDir,
                    'link' => $type === NET_SFTP_TYPE_SYMLINK,
                    'size' => $isDir ? null : (isset($attr['size']) ? (int) $attr['size'] : null),
                    'mtime' => isset($attr['mtime']) ? (int) $attr['mtime'] : null,
                ];
                if ($isDir && isset($expand[$path])) {
                    $queue[] = [$dir.'/'.$name, $path];
                }
            }
        }

        return ['items' => $items, 'truncated' => false];
    }

    /**
     * SHA-256 of a file's content, computed while streaming it from the server (never stored, never held
     * in memory as a whole). SFTP has no portable "hash this file" request, so the bytes are read once.
     * Returns null for folders, missing files and files above $maxBytes.
     */
    public function hashFile(string $rel, int $maxBytes): ?string
    {
        $path = $this->target($this->normalize($rel));
        $stat = $this->sftp->stat($path);
        if ($stat === false || ($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY || ($stat['size'] ?? 0) > $maxBytes) {
            return null;
        }

        $context = hash_init('sha256');
        $ok = $this->sftp->get($path, function (string $chunk) use ($context): void {
            hash_update($context, $chunk);
        });

        return $ok === false ? null : hash_final($context);
    }

    /**
     * Search file names (case-insensitive) below a folder. Symlinked folders are not followed.
     */
    public function search(string $rel, string $query, int $maxResults = 300, int $maxDirs = 2000): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $rel = $this->normalize($rel);
        $queue = [[$this->target($rel), $rel]];
        $results = [];
        $scanned = 0;

        while ($queue && $scanned < $maxDirs && count($results) < $maxResults) {
            [$dir, $dirRel] = array_shift($queue);
            $scanned++;
            $raw = $this->sftp->rawlist($dir);
            if (! is_array($raw)) {
                continue; // unreadable folder: skip silently
            }
            foreach ($raw as $name => $attr) {
                $name = (string) $name;
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $childRel = $this->join($dirRel, $name);
                if (str_contains(mb_strtolower($name), $query)) {
                    $results[] = $this->describe($dir.'/'.$name, $childRel, $attr);
                    if (count($results) >= $maxResults) {
                        break;
                    }
                }
                if (($attr['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY) {
                    $queue[] = [$dir.'/'.$name, $childRel];
                }
            }
        }

        return ['items' => $results, 'truncated' => (bool) $queue || count($results) >= $maxResults];
    }

    // ---------------------------------------------------------------------
    // Reading / writing
    // ---------------------------------------------------------------------

    /**
     * Read a text file for the editor.
     *
     * @return array{content: ?string, binary: bool, tooLarge: bool, size: int, mtime: ?int}
     */
    public function readFile(string $rel): array
    {
        $path = $this->target($this->normalize($rel));
        $stat = $this->sftp->stat($path);
        if ($stat === false) {
            throw new SftpException('الملف غير موجود.', 404);
        }
        if (($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY) {
            throw new SftpException('هذا مجلد وليس ملفًا.', 422);
        }

        $size = (int) ($stat['size'] ?? 0);
        $result = ['content' => null, 'binary' => false, 'tooLarge' => false, 'size' => $size, 'mtime' => $stat['mtime'] ?? null];

        if ($size > self::MAX_EDIT_SIZE) {
            $result['tooLarge'] = true;

            return $result;
        }

        $content = $this->call('تعذر قراءة الملف.', fn () => $this->sftp->get($path));

        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            $result['binary'] = true;
        } else {
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Save text content. When the version the editor opened is given (mtime + size) and the remote file
     * no longer matches it — or was deleted — a 409 is thrown so the UI can ask before overwriting.
     *
     * @return array{mtime: ?int, size: int}
     */
    public function writeFile(string $rel, string $content, ?int $expectedMtime = null, ?int $expectedSize = null): array
    {
        $path = $this->target($this->normalize($rel));

        if ($expectedMtime !== null || $expectedSize !== null) {
            $stat = $this->sftp->stat($path);
            if ($stat === false) {
                throw new SftpException('تم حذف هذا الملف من السيرفر بعد فتحه.', 409, ['server' => null]);
            }
            $mtime = isset($stat['mtime']) ? (int) $stat['mtime'] : null;
            $size = (int) ($stat['size'] ?? 0);
            if (($expectedMtime !== null && $mtime !== $expectedMtime) || ($expectedSize !== null && $size !== $expectedSize)) {
                throw new SftpException('تم تعديل هذا الملف على السيرفر بعد فتحه.', 409, ['server' => ['mtime' => $mtime, 'size' => $size]]);
            }
        }

        $this->call('تعذر حفظ الملف.', fn () => $this->sftp->put($path, $content));

        $this->sftp->clearStatCache();
        $stat = $this->sftp->stat($path);

        return ['mtime' => $stat['mtime'] ?? null, 'size' => strlen($content)];
    }

    public function createFile(string $dirRel, string $name): string
    {
        $path = $this->newChildPath($dirRel, $name);
        $this->call('تعذر إنشاء الملف.', fn () => $this->sftp->put($path, ''));

        return $name;
    }

    public function createDirectory(string $dirRel, string $name): string
    {
        $path = $this->newChildPath($dirRel, $name);
        $this->call('تعذر إنشاء المجلد.', fn () => $this->sftp->mkdir($path));

        return $name;
    }

    /**
     * Upload one chunk of a file. Chunks are written to a hidden ".part" file which is renamed
     * to the final name once the last chunk arrives, so an interrupted upload never leaves
     * a half-written file under the real name. Returns true when the upload is complete.
     */
    public function upload(string $dirRel, string $name, string $uploadId, int $offset, int $total, ?string $localFile, bool $overwrite): bool
    {
        $this->validateName($name);
        if (! preg_match('/^[A-Za-z0-9]{8,40}$/', $uploadId) || $offset < 0 || $total < 0) {
            throw new SftpException('طلب رفع غير صالح.', 422);
        }

        $dir = $this->target($this->normalize($dirRel));
        $final = $this->joinAbs($dir, $name);
        $part = $this->joinAbs($dir, '.'.$name.'.'.$uploadId.'.part');

        if ($offset === 0) {
            $existing = $this->sftp->lstat($final);
            if ($existing !== false && (! $overwrite || ($existing['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY)) {
                throw new SftpException("الملف \"{$name}\" موجود مسبقًا.", 409);
            }
        }

        if ($total === 0) {
            $this->call('تعذر رفع الملف.', fn () => $this->sftp->put($final, ''));

            return true;
        }

        if ($localFile === null || ! is_file($localFile)) {
            throw new SftpException('تعذر رفع الملف.', 422);
        }

        // Streams from the local temp file; the chunk is never fully loaded in memory.
        $this->call('تعذر رفع الملف.', fn () => $this->sftp->put($part, $localFile, SFTP::SOURCE_LOCAL_FILE, $offset));

        if ($offset + filesize($localFile) < $total) {
            return false;
        }

        $this->sftp->clearStatCache();
        $stat = $this->sftp->stat($part);
        if ($stat === false || (int) $stat['size'] !== $total) {
            $this->sftp->delete($part, false);
            throw new SftpException('تعذر رفع الملف: الحجم غير مكتمل.', 422);
        }

        if ($this->sftp->lstat($final) !== false) {
            if (! $overwrite) {
                throw new SftpException("الملف \"{$name}\" أصبح موجودًا أثناء الرفع ولم يُستبدل.", 409);
            }
            $this->call('تعذر استبدال الملف الموجود.', fn () => $this->sftp->delete($final, false));
        }
        $this->call('تعذر رفع الملف.', fn () => $this->sftp->rename($part, $final));

        return true;
    }

    /**
     * Bytes already stored for an interrupted upload (its hidden .part file), so it can resume
     * from there. Null when nothing was stored yet.
     */
    public function uploadStatus(string $dirRel, string $name, string $uploadId): ?int
    {
        $this->validateName($name);
        if (! preg_match('/^[A-Za-z0-9]{8,40}$/', $uploadId)) {
            throw new SftpException('طلب رفع غير صالح.', 422);
        }
        $dir = $this->target($this->normalize($dirRel));
        $this->sftp->clearStatCache();
        $stat = $this->sftp->stat($this->joinAbs($dir, '.'.$name.'.'.$uploadId.'.part'));

        return $stat === false ? null : (int) ($stat['size'] ?? 0);
    }

    public function abortUpload(string $dirRel, string $name, string $uploadId): void
    {
        $this->validateName($name);
        if (! preg_match('/^[A-Za-z0-9]{8,40}$/', $uploadId)) {
            return;
        }
        $dir = $this->target($this->normalize($dirRel));
        $this->sftp->delete($this->joinAbs($dir, '.'.$name.'.'.$uploadId.'.part'), false);
    }

    /**
     * Prepare a streamed download.
     *
     * @return array{name: string, size: int, mtime: ?int, stream: Closure(callable(string): void): mixed}
     */
    public function download(string $rel): array
    {
        $rel = $this->normalize($rel);
        $path = $this->target($rel);
        $stat = $this->sftp->stat($path);
        if ($stat === false) {
            throw new SftpException('الملف غير موجود.', 404);
        }
        if (($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY) {
            throw new SftpException('لا يمكن تنزيل مجلد.', 422);
        }
        // Check the file is readable before any response headers are sent (clear "Permission denied").
        if (($stat['size'] ?? 0) > 0) {
            $this->call('تعذر قراءة الملف.', fn () => $this->sftp->get($path, false, 0, 1));
        }

        return [
            'name' => basename($rel),
            'size' => (int) ($stat['size'] ?? 0),
            'mtime' => $stat['mtime'] ?? null,
            // phpseclib hands every received packet to the callback, so the file is never held in memory.
            'stream' => fn (callable $write) => $this->sftp->get($path, $write),
        ];
    }

    // ---------------------------------------------------------------------
    // Rename / delete / copy / move
    // ---------------------------------------------------------------------

    public function rename(string $rel, string $newName): string
    {
        $this->validateName($newName);
        $rel = $this->normalize($rel);
        $this->assertNotRoot($rel);

        $src = $this->entry($rel);
        $dst = $this->joinAbs(dirname($src), $newName);
        if ($src === $dst) {
            return $newName;
        }
        // Allow pure case changes on case-insensitive servers, otherwise refuse to overwrite.
        if (strcasecmp($src, $dst) !== 0 && $this->sftp->lstat($dst) !== false) {
            throw new SftpException('يوجد ملف أو مجلد بنفس الاسم.', 409);
        }

        $this->call('تعذرت إعادة التسمية.', fn () => $this->sftp->rename($src, $dst));

        return $newName;
    }

    public function delete(string $rel): void
    {
        $rel = $this->normalize($rel);
        $this->assertNotRoot($rel);

        $entry = $this->entry($rel);
        $attr = $this->sftp->lstat($entry);
        if ($attr === false) {
            throw new SftpException('الملف أو المجلد غير موجود.', 404);
        }

        // Symlinks are removed as links (their target is never touched).
        // phpseclib's recursive delete does not follow symlinks inside folders either.
        $recursive = ($attr['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY;
        $this->call('تعذر الحذف.', fn () => $this->sftp->delete($entry, $recursive));
    }

    /** Number of direct children in a folder (used for the delete confirmation). */
    public function countChildren(string $rel): int
    {
        $list = $this->sftp->nlist($this->target($this->normalize($rel)));

        return is_array($list) ? count(array_diff($list, ['.', '..'])) : 0;
    }

    /**
     * Copy a file/folder into another folder on the same server. Data is streamed
     * server → app server temp file → server; it never goes through the user's browser.
     * Returns the name of the created copy.
     */
    public function copy(string $rel, string $destDirRel): array
    {
        $rel = $this->normalize($rel);
        $this->assertNotRoot($rel);

        $src = $this->target($rel);
        $destDir = $this->target($this->normalize($destDirRel));

        $stat = $this->sftp->stat($src);
        if ($stat === false) {
            throw new SftpException('الملف أو المجلد غير موجود.', 404);
        }
        $isDir = ($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY;
        if ($isDir && ($destDir === $src || str_starts_with($destDir.'/', rtrim($src, '/').'/'))) {
            throw new SftpException('لا يمكن نسخ مجلد إلى داخل نفسه.', 422);
        }

        $name = $this->availableName($destDir, basename($rel), $isDir);
        $skipped = 0;
        $this->copyRecursive($src, $this->joinAbs($destDir, $name), $isDir, $skipped);

        return ['name' => $name, 'skipped' => $skipped];
    }

    public function move(string $rel, string $destDirRel): string
    {
        $rel = $this->normalize($rel);
        $this->assertNotRoot($rel);

        $src = $this->entry($rel);
        $destDir = $this->target($this->normalize($destDirRel));
        $name = basename($rel);
        $dst = $this->joinAbs($destDir, $name);

        if ($dst === $src) {
            return $name; // pasting into the same folder: nothing to do
        }
        if (str_starts_with($destDir.'/', rtrim($src, '/').'/')) {
            throw new SftpException('لا يمكن نقل مجلد إلى داخل نفسه.', 422);
        }
        if ($this->sftp->lstat($dst) !== false) {
            throw new SftpException("يوجد عنصر باسم \"{$name}\" في المجلد الهدف.", 409);
        }

        // Native SFTP rename: happens entirely on the remote server.
        $this->call('تعذر نقل العنصر.', fn () => $this->sftp->rename($src, $dst));

        return $name;
    }

    // ---------------------------------------------------------------------
    // Archives: moving whole files/trees between the server and a local folder
    // ---------------------------------------------------------------------

    /**
     * Download one remote file to a local path.
     *
     * @param  callable(int): void|null  $progress  bytes received so far
     * @return array{size: int, mtime: ?int}
     */
    public function fetch(string $rel, string $localFile, ?callable $progress = null): array
    {
        $path = $this->target($this->normalize($rel));
        $stat = $this->sftp->stat($path);
        if ($stat === false || ($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY) {
            throw new SftpException('الملف غير موجود.', 404);
        }

        $this->call('تعذر تنزيل الملف.', fn () => $this->sftp->get($path, $localFile, 0, -1, $progress));

        return ['size' => (int) ($stat['size'] ?? 0), 'mtime' => $stat['mtime'] ?? null];
    }

    /** Size and modification time of a remote file (used to reuse a cached download). */
    public function fileInfo(string $rel): array
    {
        $stat = $this->sftp->stat($this->target($this->normalize($rel)));
        if ($stat === false) {
            throw new SftpException('الملف غير موجود.', 404);
        }

        return ['size' => (int) ($stat['size'] ?? 0), 'mtime' => $stat['mtime'] ?? null, 'dir' => ($stat['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY];
    }

    /**
     * Everything below the given items, as files and folders relative to their parent folder
     * (each selected item keeps its own name). Symlinks inside folders are skipped.
     *
     * @param  list<string>  $rels
     * @return array{items: list<array{rel: string, abs: string, dir: bool, size: int}>, bytes: int}
     */
    public function collect(array $rels, int $maxBytes, int $maxItems = 100000): array
    {
        $items = [];
        $bytes = 0;
        $add = function (array $item) use (&$items, &$bytes, $maxBytes, $maxItems): void {
            $items[] = $item;
            $bytes += $item['size'];
            if (count($items) > $maxItems) {
                throw new SftpException('عدد الملفات المحددة كبير جدًا.', 422);
            }
            if ($bytes > $maxBytes) {
                throw new SftpException('حجم الملفات المحددة أكبر من الحد المسموح ('.intdiv($maxBytes, 1024 ** 3).' GB).', 422);
            }
        };

        foreach ($rels as $rel) {
            $rel = $this->normalize($rel);
            $this->assertNotRoot($rel);
            $abs = $this->target($rel);
            $stat = $this->sftp->stat($abs);
            if ($stat === false) {
                throw new SftpException('الملف أو المجلد غير موجود.', 404);
            }
            $name = basename($rel);

            if (($stat['type'] ?? null) !== NET_SFTP_TYPE_DIRECTORY) {
                $add(['rel' => $name, 'abs' => $abs, 'dir' => false, 'size' => (int) ($stat['size'] ?? 0)]);

                continue;
            }

            $add(['rel' => $name, 'abs' => $abs, 'dir' => true, 'size' => 0]);
            $queue = [[$abs, $name]];
            while ($queue) {
                [$dir, $prefix] = array_shift($queue);
                $raw = $this->call('تعذر قراءة المجلد.', fn () => $this->sftp->rawlist($dir));
                foreach ($raw as $child => $attr) {
                    $child = (string) $child;
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    $type = $attr['type'] ?? null;
                    $childAbs = $dir.'/'.$child;
                    $childRel = $prefix.'/'.$child;
                    if ($type === NET_SFTP_TYPE_DIRECTORY) {
                        $add(['rel' => $childRel, 'abs' => $childAbs, 'dir' => true, 'size' => 0]);
                        $queue[] = [$childAbs, $childRel];
                    } elseif ($type === NET_SFTP_TYPE_REGULAR) {
                        $add(['rel' => $childRel, 'abs' => $childAbs, 'dir' => false, 'size' => (int) ($attr['size'] ?? 0)]);
                    }
                }
            }
        }

        return ['items' => $items, 'bytes' => $bytes];
    }

    /**
     * Download collected items into a local folder, keeping their structure.
     *
     * @param  list<array{rel: string, abs: string, dir: bool, size: int}>  $items
     * @param  callable(int): void|null  $progress  total bytes received so far
     */
    public function fetchTree(array $items, string $localDir, ?callable $progress = null): void
    {
        $done = 0;
        foreach ($items as $item) {
            $local = $localDir.'/'.$item['rel'];
            if ($item['dir']) {
                @mkdir($local, 0700, true);

                continue;
            }
            @mkdir(dirname($local), 0700, true);
            $base = $done;
            $this->call('تعذر تنزيل الملف.', fn () => $this->sftp->get($item['abs'], $local, 0, -1, $progress ? fn (int $bytes) => $progress($base + $bytes) : null));
            $done += $item['size'];
            if ($progress) {
                $progress($done);
            }
        }
    }

    /** Create a folder (and missing parents) below a root-relative folder. */
    public function makeDirs(string $dirRel, string $sub): void
    {
        $sub = trim($sub, '/');
        if ($sub === '') {
            return;
        }
        $path = $this->joinAbs($this->target($this->normalize($dirRel)), $this->normalize($sub));
        if ($this->sftp->is_dir($path)) {
            return;
        }
        $this->call('تعذر إنشاء المجلد.', fn () => $this->sftp->mkdir($path, -1, true));
    }

    /**
     * Upload a local file to $dirRel/$relPath. Returns false when it already exists and
     * $overwrite is false (the file is then left untouched).
     */
    public function putFile(string $dirRel, string $relPath, string $localFile, bool $overwrite, ?callable $progress = null): bool
    {
        $path = $this->joinAbs($this->target($this->normalize($dirRel)), $this->normalize($relPath));
        if (! $overwrite && $this->sftp->lstat($path) !== false) {
            return false;
        }
        $this->call('تعذر رفع الملف.', fn () => $this->sftp->put($path, $localFile, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progress));

        return true;
    }

    /** A name that does not exist yet in the folder: "backup", "backup (2)", … */
    public function freeName(string $dirRel, string $name, bool $isDir): string
    {
        $dir = $this->target($this->normalize($dirRel));
        if ($this->sftp->lstat($this->joinAbs($dir, $name)) === false) {
            return $name;
        }

        $ext = '';
        $base = $name;
        if (! $isDir && ($dot = strrpos($name, '.')) > 0) {
            $base = substr($name, 0, $dot);
            $ext = substr($name, $dot);
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = "{$base} ({$i}){$ext}";
            if ($this->sftp->lstat($this->joinAbs($dir, $candidate)) === false) {
                return $candidate;
            }
        }

        throw new SftpException('تعذر إيجاد اسم متاح.', 409);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function copyRecursive(string $src, string $dst, bool $isDir, int &$skipped): void
    {
        if (! $isDir) {
            $tmp = tmpfile();
            try {
                $this->call('تعذر نسخ الملف.', fn () => $this->sftp->get($src, $tmp));
                rewind($tmp);
                $this->call('تعذر نسخ الملف.', fn () => $this->sftp->put($dst, $tmp));
            } finally {
                if (is_resource($tmp)) {
                    fclose($tmp);
                }
            }

            return;
        }

        $this->call('تعذر إنشاء المجلد.', fn () => $this->sftp->mkdir($dst));
        $entries = $this->call('تعذر قراءة المجلد.', fn () => $this->sftp->rawlist($src));

        foreach ($entries as $name => $attr) {
            $name = (string) $name;
            if ($name === '.' || $name === '..') {
                continue;
            }
            $type = $attr['type'] ?? null;
            if ($type === NET_SFTP_TYPE_SYMLINK || ! in_array($type, [NET_SFTP_TYPE_DIRECTORY, NET_SFTP_TYPE_REGULAR], true)) {
                $skipped++; // symlinks / special files are not copied

                continue;
            }
            $this->copyRecursive($src.'/'.$name, $dst.'/'.$name, $type === NET_SFTP_TYPE_DIRECTORY, $skipped);
        }
    }

    /** "report.txt" → "report - copy.txt", "report - copy (2).txt", ... */
    private function availableName(string $dir, string $name, bool $isDir): string
    {
        if ($this->sftp->lstat($this->joinAbs($dir, $name)) === false) {
            return $name;
        }

        $ext = '';
        $base = $name;
        if (! $isDir && ($dot = strrpos($name, '.')) > 0) {
            $base = substr($name, 0, $dot);
            $ext = substr($name, $dot);
        }

        for ($i = 1; $i < 1000; $i++) {
            $candidate = $base.' - copy'.($i > 1 ? " ({$i})" : '').$ext;
            if ($this->sftp->lstat($this->joinAbs($dir, $candidate)) === false) {
                return $candidate;
            }
        }

        throw new SftpException('تعذر إيجاد اسم متاح للنسخة.', 409);
    }

    private function describe(string $abs, string $rel, array $attr): array
    {
        $type = $attr['type'] ?? null;
        $isLink = $type === NET_SFTP_TYPE_SYMLINK;
        $isDir = $type === NET_SFTP_TYPE_DIRECTORY;
        $size = $attr['size'] ?? null;
        $mtime = $attr['mtime'] ?? null;

        if ($isLink) {
            $target = $this->sftp->stat($abs);
            if ($target !== false) {
                $isDir = ($target['type'] ?? null) === NET_SFTP_TYPE_DIRECTORY;
                $size = $target['size'] ?? $size;
                $mtime = $target['mtime'] ?? $mtime;
            }
        }

        return [
            'name' => $rel === '' ? '/' : basename($rel),
            'path' => $rel,
            'dir' => $isDir,
            'link' => $isLink,
            'size' => $isDir ? null : ($size !== null ? (int) $size : null),
            'mtime' => $mtime !== null ? (int) $mtime : null,
            // Numeric owner/group from SFTP (like `ls -ln`); names are added by the controller.
            'uid' => isset($attr['uid']) ? (int) $attr['uid'] : null,
            'gid' => isset($attr['gid']) ? (int) $attr['gid'] : null,
        ];
    }

    /**
     * Map numeric user/group IDs to names by reading the server's /etc/passwd and /etc/group over SFTP.
     *
     * These system files are read only to build the ID → name map; their contents are never returned.
     * On chrooted or restricted accounts they are usually not readable: callers then show the numbers.
     *
     * @return array{users: array<int, string>, groups: array<int, string>}
     */
    public function accountNames(): array
    {
        $parse = function (string $file): array {
            $content = $this->sftp->get($file, false, 0, 1024 * 1024);
            $map = [];
            if (! is_string($content)) {
                return $map;
            }
            foreach (explode("\n", $content) as $line) {
                $parts = explode(':', $line);
                if (count($parts) >= 3 && $parts[0] !== '' && ctype_digit($parts[2])) {
                    $map[(int) $parts[2]] ??= $parts[0];
                }
            }

            return $map;
        };

        try {
            return ['users' => $parse('/etc/passwd'), 'groups' => $parse('/etc/group')];
        } catch (Throwable) {
            return ['users' => [], 'groups' => []];
        }
    }

    private function newChildPath(string $dirRel, string $name): string
    {
        $this->validateName($name);
        $path = $this->joinAbs($this->target($this->normalize($dirRel)), $name);
        if ($this->sftp->lstat($path) !== false) {
            throw new SftpException('يوجد ملف أو مجلد بنفس الاسم.', 409);
        }

        return $path;
    }

    /**
     * Run an SFTP call and translate a `false` result into a friendly error based on the
     * status code the server returned for *this* call.
     */
    private function call(string $message, Closure $fn): mixed
    {
        $before = count($this->sftp->getSFTPErrors());
        $result = $fn();
        if ($result !== false) {
            return $result;
        }

        if (! $this->sftp->isConnected()) {
            throw new SftpException(self::MSG_CONNECTION_LOST, 503);
        }

        $errors = array_slice($this->sftp->getSFTPErrors(), $before);
        $error = (string) end($errors);

        throw match (true) {
            str_contains($error, 'PERMISSION_DENIED') => new SftpException('تم رفض الصلاحية (Permission denied).', 403),
            str_contains($error, 'NO_SUCH_FILE') => new SftpException('الملف أو المجلد غير موجود.', 404),
            str_contains($error, 'FILE_ALREADY_EXISTS') => new SftpException('يوجد ملف أو مجلد بنفس الاسم.', 409),
            str_contains($error, 'DIR_NOT_EMPTY') => new SftpException('المجلد غير فارغ.', 409),
            default => new SftpException($message, 400),
        };
    }

    /**
     * Clean a client-supplied relative path. Any ".." segment is rejected outright.
     */
    private function normalize(string $rel): string
    {
        if (str_contains($rel, "\0") || str_contains($rel, '\\')) {
            throw new SftpException('المسار غير صالح.', 422);
        }

        $parts = [];
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new SftpException('المسار غير مسموح به.', 403);
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function validateName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new SftpException('الاسم غير صالح.', 422);
        }
    }

    private function assertNotRoot(string $rel): void
    {
        if ($rel === '') {
            throw new SftpException('لا يمكن تنفيذ هذه العملية على المجلد الجذر.', 403);
        }
    }

    /** Absolute, fully symlink-resolved path of an existing item; must be inside the root. */
    private function target(string $rel): string
    {
        $path = $this->resolve($this->joinAbs($this->root, $rel));
        $this->assertInsideRoot($path);

        return $path;
    }

    /**
     * Absolute path of the item itself: the parent folder is resolved, the last segment is not
     * (so renaming/deleting a symlink acts on the link, not on what it points to).
     */
    private function entry(string $rel): string
    {
        if ($rel === '') {
            return $this->root;
        }
        $parent = $this->target(str_contains($rel, '/') ? dirname($rel) : '');

        return $this->joinAbs($parent, basename($rel));
    }

    private function assertInsideRoot(string $path): void
    {
        $root = rtrim($this->root, '/');
        if ($root !== '' && $path !== $root && ! str_starts_with($path, $root.'/')) {
            throw new SftpException('الوصول خارج المسار المحدد غير مسموح.', 403);
        }
    }

    /**
     * Resolve symlinks component by component (SFTP has no reliable "realpath" that follows links in
     * phpseclib's public API). Components that don't exist are kept as-is.
     */
    private function resolve(string $path, int &$hops = 0): string
    {
        $current = '';
        $rest = $path;

        // The root is already fully resolved; skip walking it again.
        $root = rtrim($this->root, '/');
        if ($root !== '' && ($path === $root || str_starts_with($path, $root.'/'))) {
            $current = $root;
            $rest = substr($path, strlen($root));
        }

        $parts = explode('/', $rest);
        while ($parts) {
            $part = array_shift($parts);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $current = $current === '' ? '' : rtrim(dirname($current), '/');

                continue;
            }

            $candidate = $current.'/'.$part;
            $attr = $this->sftp->lstat($candidate);
            if ($attr !== false && ($attr['type'] ?? null) === NET_SFTP_TYPE_SYMLINK) {
                if (++$hops > 32) {
                    throw new SftpException('روابط رمزية كثيرة جدًا (Too many symlinks).', 422);
                }
                $link = $this->sftp->readlink($candidate);
                if ($link !== false && $link !== '') {
                    $base = $link[0] === '/' ? $link : $current.'/'.$link;
                    $current = rtrim($this->resolve($base, $hops), '/');

                    continue;
                }
            }
            $current = $candidate;
        }

        return $current === '' ? '/' : $current;
    }

    private function join(string $rel, string $name): string
    {
        return $rel === '' ? $name : $rel.'/'.$name;
    }

    private function joinAbs(string $dir, string $rel): string
    {
        if ($rel === '') {
            return $dir;
        }

        return rtrim($dir, '/').'/'.$rel;
    }

    private static function fingerprint(string $hostKey): string
    {
        $parts = explode(' ', $hostKey);
        $blob = base64_decode($parts[1] ?? '', true) ?: $hostKey;

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
