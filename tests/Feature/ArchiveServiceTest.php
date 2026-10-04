<?php

namespace Tests\Feature;

use App\Services\ArchiveService;
use Illuminate\Support\Facades\File;
use PharData;
use Tests\TestCase;
use ZipArchive;

class ArchiveServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/archive-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_entry_names_cannot_escape_the_target_folder(): void
    {
        $this->assertSame('a/b.txt', ArchiveService::safePath('./a/b.txt'));
        $this->assertSame('etc/passwd', ArchiveService::safePath('/etc/passwd'));
        $this->assertSame('dir/file.txt', ArchiveService::safePath('dir\\file.txt'));
        $this->assertSame('', ArchiveService::safePath('./'));
        $this->assertNull(ArchiveService::safePath('../../evil.txt'));
        $this->assertNull(ArchiveService::safePath('a/../../evil.txt'));
        $this->assertNull(ArchiveService::safePath("bad\0name"));
        $this->assertNull(ArchiveService::safePath('C:/Windows/evil.dll'));
        $this->assertNull(ArchiveService::safePath('__MACOSX/._file'));
    }

    public function test_recognises_supported_formats(): void
    {
        $this->assertSame('zip', ArchiveService::format('Site.ZIP'));
        $this->assertSame('tar.gz', ArchiveService::format('backup.tgz'));
        $this->assertSame('tar.bz2', ArchiveService::format('backup.tar.bz2'));
        $this->assertNull(ArchiveService::format('movie.rar'));
        $this->assertSame('site-backup', ArchiveService::baseName('site-backup.tar.gz'));
    }

    public function test_extracting_a_malicious_zip_only_delivers_safe_entries(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->dir.'/evil.zip', ZipArchive::CREATE);
        $zip->addFromString('../../evil.txt', 'nope');
        $zip->addFromString('safe/ok.txt', 'fine');
        $zip->close();

        $delivered = [];
        $stats = (new ArchiveService)->extract(
            $this->dir.'/evil.zip', 'zip', $this->dir,
            fn () => null,
            function (string $rel, string $tmp) use (&$delivered) {
                $delivered[$rel] = file_get_contents($tmp);
            },
        );

        $this->assertSame(['safe/ok.txt' => 'fine'], $delivered);
        $this->assertSame(1, $stats['skipped']);
        $this->assertFileDoesNotExist(dirname($this->dir, 2).'/evil.txt');
    }

    public function test_reads_tar_gz_archives_and_extracts_a_single_entry(): void
    {
        $tar = new PharData($this->dir.'/site.tar');
        $tar->addFromString('index.html', '<h1>Hi</h1>');
        $tar->addFromString('css/app.css', 'body{}');
        $tar->compress(\Phar::GZ);

        $service = new ArchiveService;
        $listing = $service->entries($this->dir.'/site.tar.gz', 'tar.gz');
        $paths = array_column(array_filter($listing['entries'], fn ($e) => ! $e['dir']), 'path');
        sort($paths);

        $this->assertSame(['css/app.css', 'index.html'], $paths);
        $this->assertTrue($service->extractEntry($this->dir.'/site.tar.gz', 'tar.gz', 'css/app.css', $this->dir.'/out.css'));
        $this->assertSame('body{}', file_get_contents($this->dir.'/out.css'));
    }

    public function test_creates_a_zip_from_a_folder(): void
    {
        File::ensureDirectoryExists($this->dir.'/src/sub');
        file_put_contents($this->dir.'/src/a.txt', 'A');
        file_put_contents($this->dir.'/src/sub/b.txt', 'B');

        (new ArchiveService)->createZip($this->dir.'/src', $this->dir.'/out.zip');

        $zip = new ZipArchive;
        $zip->open($this->dir.'/out.zip');
        $this->assertSame('B', $zip->getFromName('sub/b.txt'));
        $this->assertSame('A', $zip->getFromName('a.txt'));
        $zip->close();
    }
}
