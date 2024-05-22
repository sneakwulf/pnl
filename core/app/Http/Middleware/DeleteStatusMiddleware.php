<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DeleteStatusMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();
        if($user->is_deleted){
            auth()->logout();
            return to_route('user.login');
        }
        return $next($request);
    }
}
