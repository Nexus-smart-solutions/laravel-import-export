<?php

namespace Nexus\ImportExport\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RejectClientIdentity
{
    public function handle(Request $request, Closure $next): mixed
    {
        $reserved = ['actor_id', 'actor_type', 'user_id', 'tenant_id', 'workspace_id', 'context', 'recipient', 'recipient_id', 'recipient_email', 'email'];
        foreach ($reserved as $key) {
            if ($request->exists($key) || $request->exists('options.'.$key)) {
                throw ValidationException::withMessages([$key => 'Identity, context and notification recipients are server-controlled.']);
            }
        }

        return $next($request);
    }
}
