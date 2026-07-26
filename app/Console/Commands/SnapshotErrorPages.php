<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SnapshotErrorPages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:snapshot-error-pages {--path=storage/app/html-validate-snapshots : Output directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Render every resources/views/errors/*.blade.php view to a static HTML file, for CI HTML5-validity scanning';

    /**
     * The HTTP status codes this app has a custom error view for. Kept as an explicit list,
     * not a directory scan, so a new error view added later must be added here deliberately -
     * the same reasoning as tests/v4/Feature/ErrorPageLayoutTest.php's own dataset.
     *
     * @var list<string>
     */
    private const ERROR_CODES = ['400', '401', '402', '403', '404', '419', '429', '500', '503'];

    public function handle(): int
    {
        $path = base_path($this->option('path'));
        File::ensureDirectoryExists($path);

        // The `errors.*` views call instanceSettings(), which queries the instance_settings
        // table. In CI, the `testing` DB connection is `:memory:` (see config/database.php),
        // which only exists for this process's own lifetime - migrate and seed it here rather
        // than relying on a separate `php artisan migrate` step in CI, which would run in its
        // own process against its own, separate, immediately-discarded in-memory database.
        // Deliberately scoped to only the `testing` connection: this command must never run
        // `migrate` against a real database if invoked outside CI by mistake.
        if (config('database.default') === 'testing') {
            Artisan::call('migrate', ['--force' => true]);
            if (! InstanceSettings::query()->exists()) {
                InstanceSettings::forceCreate(['id' => 0]);
            }
        }

        foreach (self::ERROR_CODES as $code) {
            $html = view("errors.{$code}", ['exception' => new \Exception('Example error message for HTML validation.')])->render();
            File::put("{$path}/{$code}.html", $html);
            $this->line("Snapshotted errors.{$code} -> {$path}/{$code}.html");
        }

        $this->info('Done: '.count(self::ERROR_CODES).' error pages snapshotted to '.$path);

        return self::SUCCESS;
    }
}
