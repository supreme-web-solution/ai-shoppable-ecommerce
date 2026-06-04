<?php

namespace App\Http\Middleware;

use App\Support\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! PlatformAdmin::isPlatformAdmin($user)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You do not have permission to access platform administration.',
                ], 403);
            }

            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
