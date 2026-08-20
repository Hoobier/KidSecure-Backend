<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the ESP32 turnstile device using a shared secret sent as
 * a header, rather than Sanctum (admin portal) or Firebase ID tokens
 * (parent app) — neither of which a microcontroller can practically use.
 */
class VerifyDeviceSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.device.scan_secret');
        $provided = $request->header('X-Device-Secret');

        if (empty($expected) || $provided !== $expected) {
            return response()->json([
                'message' => 'Unauthorized device.',
            ], 401);
        }

        return $next($request);
    }
}