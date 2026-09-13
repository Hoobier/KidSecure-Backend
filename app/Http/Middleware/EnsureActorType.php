<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Admin;
use App\Models\Teacher;

class EnsureActorType
{
    public function handle(Request $request, Closure $next, string $type)
    {
        $user = $request->user();

        $expectedClass = match ($type) {
            'admin' => Admin::class,
            'teacher' => Teacher::class,
            default => null,
        };

        if (! $expectedClass || ! $user instanceof $expectedClass) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $next($request);
    }
}