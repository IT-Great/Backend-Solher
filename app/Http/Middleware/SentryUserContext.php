<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function Sentry\configureScope;
use Sentry\State\Scope;

class SentryUserContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Mengecek apakah ada user yang sedang terautentikasi (via Sanctum/Token)
        if (auth()->check()) {
            configureScope(function (Scope $scope): void {
                $scope->setUser([
                    'id' => auth()->id(),
                    'email' => auth()->user()->email,
                    'username' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
                    'role' => auth()->user()->usertype,
                ]);
            });
        }

        return $next($request);
    }
}