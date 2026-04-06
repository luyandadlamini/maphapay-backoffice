<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Wallet\Models\KeyAccessLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateKeyAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission = 'access_keys'): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user has permission to access keys
        if (! $user->can($permission)) {
            return response()->json(['error' => 'Forbidden - Insufficient permissions'], 403);
        }

        // Rate limiting for key access
        $key = 'key-access:' . $user->id;
        $maxAttempts = config('blockchain.key_access.max_attempts', 10);
        $decayMinutes = config('blockchain.key_access.decay_minutes', 1);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            // Log suspicious activity
            KeyAccessLog::create([
                'wallet_id'  => 'rate_limit',
                'user_id'    => $user->id,
                'action'     => 'rate_limited',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata'   => [
                    'attempts'    => $maxAttempts,
                    'retry_after' => $seconds,
                ],
                'accessed_at' => now(),
            ]);

            return response()->json([
                'error'       => 'Too many key access attempts',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        // Check for suspicious patterns
        $this->checkSuspiciousActivity($user, $request);

        return $next($request);
    }

    /**
     * Check for suspicious key access patterns.
     */
    protected function checkSuspiciousActivity($user, Request $request): void
    {
        // Check recent access patterns
        $recentAccesses = KeyAccessLog::where('user_id', $user->id)
            ->where('accessed_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentAccesses > 20) {
            // Log suspicious activity
            KeyAccessLog::create([
                'wallet_id'  => 'suspicious_activity',
                'user_id'    => $user->id,
                'action'     => 'suspicious_pattern',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata'   => [
                    'recent_accesses' => $recentAccesses,
                    'threshold'       => 20,
                    'time_window'     => '5 minutes',
                ],
                'accessed_at' => now(),
            ]);

            // Could trigger additional security measures here
            // e.g., require 2FA, notify security team, etc.
        }

        // Check for IP changes
        $lastAccess = KeyAccessLog::where('user_id', $user->id)
            ->orderBy('accessed_at', 'desc')
            ->first();

        if ($lastAccess && $lastAccess->ip_address !== $request->ip()) {
            KeyAccessLog::create([
                'wallet_id'  => 'ip_change',
                'user_id'    => $user->id,
                'action'     => 'ip_changed',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata'   => [
                    'previous_ip' => $lastAccess->ip_address,
                    'new_ip'      => $request->ip(),
                ],
                'accessed_at' => now(),
            ]);
        }
    }
}
