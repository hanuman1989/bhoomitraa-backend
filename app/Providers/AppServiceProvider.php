<?php

namespace App\Providers;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Crop;
use App\Models\KnowledgeSourceAttachment;
use App\Observers\CategoryObserver;
use App\Observers\CropObserver;
use App\Observers\KnowledgeSourceAttachmentObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CategoryObserver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-admin-users', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-categories', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-regions', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-seasons', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-crop-categories', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-crops', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-crop-ai-diagnosis', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status && $user->is_super_user);
        Gate::define('manage-knowledge-sources', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('review-knowledge-sources', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status && $user->is_super_user);

        Category::observe(CategoryObserver::class);
        Crop::observe(CropObserver::class);
        KnowledgeSourceAttachment::observe(KnowledgeSourceAttachmentObserver::class);
        Event::listen(TransactionCommitted::class, [CategoryObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [CategoryObserver::class, 'rolledBack']);

        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by($request->path().'|'.$email.'|'.$request->ip()),
            ];
        });
    }
}
