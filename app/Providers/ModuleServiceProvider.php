<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Discovers each module under app/Modules/*: its migrations, its routes.php and its console commands (D-001).
 *
 * Laravel sorts migrations from every module by file name, so a migration that references another module's
 * table must carry a later timestamp than the migration that creates it.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ($this->modules() as $name => $path) {
            if (is_dir($path.'/Database/Migrations')) {
                $this->loadMigrationsFrom($path.'/Database/Migrations');
            }

            if (is_file($path.'/routes.php')) {
                Route::prefix('api/v1/staff')
                    ->middleware('api')
                    ->group($path.'/routes.php');
            }

            if ($this->app->runningInConsole() && is_dir($path.'/Console')) {
                $this->commands($this->commandsOf($name, $path.'/Console'));
            }
        }
    }

    /**
     * @return array<string, string> module name => absolute path
     */
    private function modules(): array
    {
        $modules = [];

        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $path) {
            $modules[basename($path)] = $path;
        }

        ksort($modules);

        return $modules;
    }

    /**
     * @return list<class-string>
     */
    private function commandsOf(string $module, string $directory): array
    {
        $commands = [];

        foreach (glob($directory.'/*Command.php') ?: [] as $file) {
            $commands[] = 'App\\Modules\\'.$module.'\\Console\\'.basename($file, '.php');
        }

        return $commands;
    }
}
