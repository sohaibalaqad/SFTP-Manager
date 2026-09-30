<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds a ready-to-share zip of the app for friends: no PHP, Composer or Node needed on their computer.
 *
 * The zip contains the app with production dependencies and the built frontend, plus double-click
 * launchers for macOS, Windows and Linux. The launcher downloads the FrankenPHP engine (PHP included)
 * on first run and verifies its SHA-256. No .env, keys, sessions or logs are included.
 */
#[Signature('app:package {--output= : Path of the zip to create (default: SFTP-Manager.zip in the project folder)}')]
#[Description('Build a zip that friends can run locally by double-clicking a launcher')]
class BuildPackage extends Command
{
    private const NAME = 'SFTP-Manager';

    /** Files and folders copied from the project into the package. */
    private const INCLUDE = [
        'app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'public', 'resources/views',
        'routes', 'artisan', 'composer.json', 'composer.lock', '.env.example', 'Caddyfile',
    ];

    public function handle(): int
    {
        if (! File::exists(public_path('build/manifest.json'))) {
            $this->components->error('The frontend is not built. Run `npm run build` first.');

            return self::FAILURE;
        }

        $work = storage_path('app/package-build');
        $dir = $work.'/'.self::NAME;
        $zip = $this->option('output') ?: base_path(self::NAME.'.zip');

        File::deleteDirectory($work);
        File::ensureDirectoryExists($dir);

        $this->components->task('Copying app files', fn () => $this->copyFiles($dir));
        $this->components->task('Installing production dependencies', fn () => $this->runProcess(
            ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'], $dir,
        ));
        $this->components->task('Adding launchers and instructions', fn () => $this->addLaunchers($dir));
        $this->components->task('Creating zip', function () use ($work, $zip) {
            File::delete($zip);

            // `zip` keeps the executable bit of the macOS/Linux launchers.
            return $this->runProcess(['zip', '-r', '-X', '-q', $zip, self::NAME], $work);
        });

        File::deleteDirectory($work);

        $this->newLine();
        $this->components->info(sprintf('Package ready: %s (%.1f MB)', $zip, File::size($zip) / 1048576));

        return self::SUCCESS;
    }

    private function copyFiles(string $dir): bool
    {
        foreach (self::INCLUDE as $path) {
            $from = base_path($path);
            $to = $dir.'/'.$path;
            File::ensureDirectoryExists(dirname($to));
            is_dir($from) ? File::copyDirectory($from, $to) : File::copy($from, $to);
        }

        // Development-only or machine-specific files never go into the package.
        File::delete([$dir.'/public/hot', $dir.'/public/storage']);
        File::delete(File::glob($dir.'/bootstrap/cache/*.php'));

        // Empty, writable storage skeleton.
        foreach (['app/transfer-logs', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $sub) {
            File::ensureDirectoryExists($dir.'/storage/'.$sub);
        }
        File::ensureDirectoryExists($dir.'/bootstrap/cache');

        return true;
    }

    private function addLaunchers(string $dir): bool
    {
        $unix = resource_path('launchers/start.sh');
        foreach (['Start SFTP Manager.command', 'start.sh'] as $name) {
            File::copy($unix, $dir.'/'.$name);
            chmod($dir.'/'.$name, 0755);
        }

        // Windows batch files need CRLF line endings.
        $bat = preg_replace("/\r?\n/", "\r\n", File::get(resource_path('launchers/start.bat')));
        File::put($dir.'/Start SFTP Manager (Windows).bat', $bat);

        File::copy(resource_path('launchers/README.txt'), $dir.'/README.txt');

        return true;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): bool
    {
        $process = new Process($command, $cwd, null, null, 600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return true;
    }
}
