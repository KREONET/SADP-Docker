<?php

namespace App\Http\Middleware;

use App\Support\InterfaceLocale;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetInterfaceLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = InterfaceLocale::forRequest($request, $request->user());

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
