<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);

        Gate::define('view-tenders', fn (User $user): bool => $user->company_id !== null);

        Gate::define('manage-tender-workflow', fn (User $user): bool => $user->company_id !== null
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
    }
}
