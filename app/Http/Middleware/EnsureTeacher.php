<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacher
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'teacher') {
            return response()->json([
                'message' => 'This area is for faculty accounts only.',
            ], 403);
        }

        return $next($request);
    }
}
