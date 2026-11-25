<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Honors Accept-Language (ar|en) so validation messages can be localized. */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));
        if (in_array($lang, ['ar', 'en'], true)) {
            app()->setLocale($lang);
        }

        return $next($request);
    }
}
