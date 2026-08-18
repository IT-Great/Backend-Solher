<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    // protected function gate(): void
    // {
    //     Gate::define('viewTelescope', function ($user) {
    //         // 👇 Sesuaikan dengan field role/usertype di tabel users Anda 👇
    //         return in_array($user->usertype, ['superadmin']); 
    //     });
    // }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user = null) {
            // 👇 BYPASS KEAMANAN JIKA MENDAPAT TOKEN RAHASIA DARI IFRAME VUE 👇
            if (request()->query('token') === env('TELESCOPE_TOKEN', 'solher-secure-telescope-123')) {
                return true;
            }

            // Fallback default Laravel jika diakses langsung
            return $user && in_array($user->usertype, ['superadmin']);
        });
    }
}
