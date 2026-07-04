<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiSensitiveData
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()->currentAccessToken();

        // Allow access to sensitive data if token has root or read:sensitive permission
        $request->attributes->add([
            'can_read_sensitive' => $token->can('root') || $token->can('read:sensitive'),
        ]);

        return $next($request);
    }
}
