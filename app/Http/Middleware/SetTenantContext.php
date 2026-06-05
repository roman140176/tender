<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        app(TenantContext::class)->set($request->user()?->company_id);

        return $next($request);
    }
}
