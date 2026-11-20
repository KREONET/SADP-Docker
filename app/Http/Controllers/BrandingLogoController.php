<?php

namespace App\Http\Controllers;

use App\Services\BrandingLogoStorageService;
use Illuminate\Http\Response;

/**
 * Serves the custom logo without authentication, because the sign-in pages show it.
 * The CSP sandbox keeps an uploaded SVG from running scripts when opened directly.
 */
class BrandingLogoController extends Controller
{
    public function __invoke(BrandingLogoStorageService $logoStorage): Response
    {
        try {
            $logo = $logoStorage->read(instanceSettings());
        } catch (\Throwable) {
            $logo = null;
        }
        abort_if($logo === null, 404);

        return response($logo['contents'], 200, [
            'Content-Type' => $logo['content_type'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
