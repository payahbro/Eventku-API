<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'message' => 'Access Denied. Admin privileges required.'
            ], 403);
        }
        

        return $next($request);
    }
}