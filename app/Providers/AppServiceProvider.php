<?php

namespace App\Providers;

use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\Username;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);

        // Assigning a column that is not fillable throws outside production instead of being dropped (D-016).
        Model::preventSilentlyDiscardingAttributes(app()->environment('production') === false);

        // An ability named like a permission slug is granted when the staff user holds it; otherwise
        // the gate says nothing (null, never false), so policies still decide (D-011).
        Gate::before(function ($user, string $ability) {
            if ($user instanceof StaffUser && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });

        $this->rateLimiters();
        $this->apiDocumentation();
    }

    /** D-014 */
    private function rateLimiters(): void
    {
        RateLimiter::for('staff-login', function (Request $request) {
            $username = Username::normalize((string) $request->input('username', ''));

            return [
                Limit::perMinute(5)->by('login:'.$username.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('staff-challenge', function (Request $request) {
            return [
                Limit::perMinute(60)->by('challenge-ip:'.$request->ip()),
                Limit::perMinute(10)->by('challenge-device:'.(string) $request->input('device_id', '')),
            ];
        });

        RateLimiter::for('device-register', fn (Request $request) => Limit::perHour(20)->by('register:'.$request->ip()));

        RateLimiter::for('device-status', fn (Request $request) => Limit::perMinute(30)->by('status:'.$request->ip()));
    }

    /** D-021 */
    private function apiDocumentation(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }
}
