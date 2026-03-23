<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\MachinePay\DataObjects\ProblemDetail;
use App\Domain\MachinePay\Events\MppChallengeIssued;
use App\Domain\MachinePay\Exceptions\MppSettlementException;
use App\Domain\MachinePay\Services\MppChallengeService;
use App\Domain\MachinePay\Services\MppHeaderCodecService;
use App\Domain\MachinePay\Services\MppPricingService;
use App\Domain\MachinePay\Services\MppSettlementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Machine Payments Protocol (MPP) payment gate middleware.
 *
 * Intercepts requests to MPP-monetized endpoints and enforces the
 * MPP protocol: issues 402 challenges via WWW-Authenticate: Payment,
 * accepts credentials via Authorization: Payment, and returns
 * receipts via Payment-Receipt.
 *
 * Errors follow RFC 9457 Problem Details format.
 */
class MppPaymentGateMiddleware
{
    public function __construct(
        private readonly MppPricingService $pricing,
        private readonly MppChallengeService $challenge,
        private readonly MppHeaderCodecService $codec,
        private readonly MppSettlementService $settlement,
    ) {
    }

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('machinepay.enabled', false)) {
            return $next($request);
        }

        $routeConfig = $this->pricing->getRouteConfig($request);

        if ($routeConfig === null) {
            return $next($request);
        }

        // Check for Authorization: Payment header
        $authHeader = $request->header('Authorization', '');

        if (! is_string($authHeader) || ! str_starts_with($authHeader, 'Payment ')) {
            return $this->issueChallenge($request, $routeConfig);
        }

        // Decode credential
        try {
            $credential = $this->codec->decodeCredential($authHeader);
        } catch (Throwable $e) {
            Log::warning('MPP: Invalid credential', [
                'error' => $e->getMessage(),
                'path'  => $request->path(),
            ]);

            return $this->problemResponse(
                ProblemDetail::verificationFailed(
                    'The Authorization: Payment header could not be decoded.'
                )
            );
        }

        // Reconstruct the challenge for verification
        $challenge = $this->challenge->generateChallenge($routeConfig, (string) $request->getHost());

        // Settle
        try {
            $receipt = $this->settlement->settle($credential, $challenge);
        } catch (MppSettlementException $e) {
            Log::error('MPP: Settlement failed', [
                'error' => $e->getMessage(),
                'path'  => $request->path(),
            ]);

            return $this->problemResponse(
                ProblemDetail::settlementFailed($e->getMessage())
            );
        }

        // Attach payment metadata to request
        $request->attributes->set('mpp_payment', [
            'receipt_id'     => $receipt->receiptId,
            'rail'           => $receipt->rail,
            'amount_cents'   => $receipt->amountCents,
            'currency'       => $receipt->currency,
            'settlement_ref' => $receipt->settlementReference,
        ]);

        $response = $next($request);

        // Add Payment-Receipt header
        $response->headers->set('Payment-Receipt', $this->codec->encodeReceipt($receipt));

        return $response;
    }

    /**
     * Issue a 402 challenge with WWW-Authenticate: Payment header.
     *
     * @param \App\Domain\MachinePay\DataObjects\MonetizedResourceConfig $routeConfig
     */
    private function issueChallenge(Request $request, $routeConfig): Response
    {
        $challenge = $this->challenge->generateChallenge(
            $routeConfig,
            (string) $request->getHost(),
        );

        MppChallengeIssued::dispatch(
            $challenge->id,
            $challenge->resourceId,
            $challenge->amountCents,
            $challenge->currency,
            $challenge->availableRails,
        );

        $body = ProblemDetail::paymentRequired(
            'This endpoint requires payment via the Machine Payments Protocol.',
            $challenge->id,
        )->toArray();

        $body['challenge'] = $challenge->toArray();

        return response()->json($body, 402)
            ->withHeaders([
                'WWW-Authenticate'   => $this->codec->encodeChallenge($challenge),
                'Cache-Control'      => 'no-store',
                'X-Payment-Protocol' => 'mpp',
                'X-Payment-Version'  => (string) config('machinepay.version', 1),
            ]);
    }

    /**
     * Return an RFC 9457 Problem Details error response.
     */
    private function problemResponse(ProblemDetail $problem): Response
    {
        return response()->json($problem->toArray(), $problem->status)
            ->withHeaders([
                'Content-Type'  => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ]);
    }
}
