<?php

namespace App\Providers;

use App\Auth\OidcUserResolver;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Guard "api": stateless, xác thực bằng Bearer token OIDC (Keycloak)
        Auth::viaRequest('oidc', fn ($request) => app(OidcUserResolver::class)($request));
    }
}
