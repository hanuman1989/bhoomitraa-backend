<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('languages.supported'));
        $default = config('languages.default');
        $locale = $request->header('X-Locale') ?: $request->getPreferredLanguage(
            array_values(array_unique([$default, ...$supported])),
        );

        if (! in_array($locale, $supported, true)) {
            $locale = $default;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
