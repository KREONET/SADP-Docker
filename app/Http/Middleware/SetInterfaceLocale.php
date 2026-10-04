<?php

namespace App\Http\Middleware;

use App\Support\InterfaceLocale;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [한국어] 모든 웹 요청 시작 때 InterfaceLocale 이 고른 언어를 앱에 적용합니다. 이후 `__()` 가 그 언어로 번역합니다.
 */
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
