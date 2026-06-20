<?php

namespace App\Providers;

use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\SoapEisGateway;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\HttpMaxGateway;
use App\Notifications\MaxGateway;
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

        // ЕИС-адаптер: SoapEisGateway — реальный HTTP-транспорт
        $this->app->bind(EisGateway::class, SoapEisGateway::class);

        // MAX-транспорт: HttpMaxGateway — реальный MAX Bot API (A7)
        $this->app->bind(MaxGateway::class, HttpMaxGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Деактивированный пользователь не проходит ни один гейт (включая суперадмина);
        // null — передать управление обычной логике гейтов.
        Gate::before(fn (User $user): ?bool => $user->is_active === true ? null : false);

        Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);

        Gate::define('view-tenders', fn (User $user): bool => $user->company_id !== null);

        Gate::define('manage-tender-workflow', fn (User $user): bool => $user->company_id !== null
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));

        Gate::define('superadmin', fn (User $user): bool => $user->role === UserRole::Superadmin);

        Gate::define('viewHorizon', fn (User $user): bool => $user->role === UserRole::Superadmin);
    }
}
