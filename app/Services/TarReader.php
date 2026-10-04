<?php

namespace App\Services;

use RuntimeException;

/**
 * Minimal streaming reader for TAR archives (plain, gzip or bzip2).
 *
 * PHP's PharData cannot list archives whose entries start with "./" (the default output of
 * `tar -C dir .`), so this reads the format directly. Supports ustar, GNU long names (type L)
 * and PAX headers (path/size). Entries are visited in order; data is only read when requested.
 */
class TarReader
{
    private const BLOCK = 512;

    /** @var resource */
    private $stream;

    public function __construct(string $path, string $compression = '')
    {
        $wrapper = match ($compression) {
            'gz' => 'compress.zlib://',
            'bz2' => 'compress.bzip2://',
            default => '',
        };

        $stream = @fopen($wrapper.$path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open archive.');
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Visit every entry. The callback receives the entry and a reader:
     *   $read(?string $toFile): writes the entry data to $toFile (or discards it when null).
     * Data not read by the callback is skipped automatically.
     *
     * @param  callable(array{name: string, type: string, size: int, mtime: int}, callable(?string): void): (bool|null)  $callback  return false to stop
     */
    public function each(callable $callback): void
    {
        $longName = null;
        $pax = [];

        while (($header = $this->readExactly(self::BLOCK)) !== null) {
            if (trim($header, "\0") === '') {
                break; // end-of-archive marker
            }

            $type = $header[156] === "\0" ? '0' : $header[156];
            $size = $this->number(substr($header, 124, 12));
            $mtime = $this->number(substr($header, 136, 12));
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';
            if ($prefix !== '') {
                $name = $prefix.'/'.$name;
            }

            // Metadata entries that describe the next one.
            if ($type === 'L') {
                $longName = rtrim($this->readData($size), "\0");

                continue;
            }
            if ($type === 'x') {
                $pax = $this->parsePax($this->readData($size));

                continue;
            }
            if ($type === 'g') {
                $this->skip($size);

                continue;
            }

            $name = $pax['path'] ?? $longName ?? $name;
            $size = isset($pax['size']) ? (int) $pax['size'] : $size;
            $longName = null;
            $pax = [];

            $consumed = false;
            $read = function (?string $toFile) use ($size, &$consumed): void {
                if ($consumed) {
                    return;
                }
                $consumed = true;
                $toFile === null ? $this->skip($size) : $this->copyTo($toFile, $size);
            };

            $continue = $callback(['name' => $name, 'type' => $type, 'size' => $type === '5' ? 0 : $size, 'mtime' => $mtime], $read);

            if (! $consumed) {
                $read(null);
            }
            if ($continue === false) {
                return;
            }
        }
    }

    /** Octal field, or base-256 (GNU) when the high bit of the first byte is set. */
    private function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80)) {
            $value = ord($field[0]) & 0x7F;
            for ($i = 1, $n = strlen($field); $i < $n; $i++) {
                $value = ($value << 8) | ord($field[$i]);
            }

            return $value;
        }

        return (int) octdec(trim($field, " \0") ?: '0');
    }

    /** @return array<string, string> */
    private function parsePax(string $data): array
    {
        $out = [];
        while ($data !== '' && preg_match('/^(\d+) /', $data, $m)) {
            $length = (int) $m[1];
            $record = substr($data, strlen($m[0]), $length - strlen($m[0]) - 1);
            $data = substr($data, $length);
            if (($eq = strpos($record, '=')) !== false) {
                $out[substr($record, 0, $eq)] = substr($record, $eq + 1);
            }
        }

        return $out;
    }

    private function readData(int $size): string
    {
        $data = $size > 0 ? ($this->readExactly($size) ?? '') : '';
        $this->skipPadding($size);

        return $data;
    }

    private function copyTo(string $file, int $size): void
    {
        $out = fopen($file, 'wb');
        $left = $size;
        while ($left > 0) {
            $chunk = fread($this->stream, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                fclose($out);
                throw new RuntimeException('The archive is truncated or corrupted.');
            }
            fwrite($out, $chunk);
            $left -= strlen($chunk);
        }
        fclose($out);
        $this->skipPadding($size);
    }

    private function skip(int $size): void
    {
        // Compressed streams cannot seek reliably: read and discard.
        $left = $size + $this->padding($size);
        while ($left > 0) {
            $chunk = fread($this->stream, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $left -= strlen($chunk);
        }
    }

    private function skipPadding(int $size): void
    {
        if ($pad = $this->padding($size)) {
            $this->readExactly($pad);
        }
    }

    private function padding(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    private function readExactly(int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return $data === '' ? null : str_pad($data, $length, "\0");
            }
            $data .= $chunk;
        }

        return $data;
    }
}
