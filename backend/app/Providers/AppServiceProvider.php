<?php

namespace App\Providers;

use App\Auth\OidcUserResolver;
use App\Support\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
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

        // 1 đối tượng trả về trực tiếp {..}; danh sách phân trang vẫn có {data, links, meta}
        JsonResource::withoutWrapping();
    }
}
