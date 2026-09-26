<?php

namespace App\Services\Payout;

use App\Contracts\PayoutProviderInterface;
use App\DTOs\PayoutResponse;
use App\Exceptions\PayoutProviderTimeoutException;
use App\Models\Payout;
use Illuminate\Support\Facades\Cache;

class MockPayoutProvider implements PayoutProviderInterface
{
    public const MODE_SUCCESS = 'success';
    public const MODE_PERMANENT_FAILURE = 'permanent_failure';
    public const MODE_TIMEOUT = 'timeout';

    protected string $mode = self::MODE_SUCCESS;

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Send payout to external payment provider.
     *
     * @throws PayoutProviderTimeoutException
     */
    public function sendPayout(Payout $payout): PayoutResponse
    {
        $cacheKey = "mock_provider_transfer:{$payout->idempotency_key}";

        if ($this->mode === self::MODE_PERMANENT_FAILURE) {
            $response = PayoutResponse::failed('Provider error: Bank account is invalid or closed.');
            Cache::put($cacheKey, $response, 86400);

            return $response;
        }

        if ($this->mode === self::MODE_TIMEOUT) {
            // Simulate: The provider processed the transfer successfully on their server,
            // but the HTTP connection dropped before the client received the response.
            $transferId = 'TRF_' . strtoupper(substr(md5($payout->idempotency_key), 0, 12));
            Cache::put($cacheKey, PayoutResponse::success($transferId), 86400);

            throw new PayoutProviderTimeoutException(
                message: "Gateway timeout connecting to provider after 30000ms for Idempotency Key '{$payout->idempotency_key}'.",
                payoutId: $payout->id,
                idempotencyKey: $payout->idempotency_key,
            );
        }

        // Default: Success
        $transferId = 'TRF_' . strtoupper(substr(md5($payout->idempotency_key), 0, 12));
        $response = PayoutResponse::success($transferId);
        Cache::put($cacheKey, $response, 86400);

        return $response;
    }

    /**
     * Look up the real transfer status from provider by idempotency key.
     */
    public function getTransferStatus(string $idempotencyKey): PayoutResponse
    {
        $cacheKey = "mock_provider_transfer:{$idempotencyKey}";
        $cached = Cache::get($cacheKey);

        if ($cached instanceof PayoutResponse) {
            return $cached;
        }

        // If not found in mock cache, default to success with deterministic ID
        $transferId = 'TRF_' . strtoupper(substr(md5($idempotencyKey), 0, 12));

        return PayoutResponse::success($transferId);
    }
}

