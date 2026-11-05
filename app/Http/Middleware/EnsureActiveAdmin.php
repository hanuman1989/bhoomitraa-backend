<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminUser = $request->user();

        abort_unless($adminUser instanceof AdminUser && $adminUser->status, 403, 'Admin account is inactive.');

        return $next($request);
    }
}
