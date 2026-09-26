<?php

namespace App\Contracts;

use App\DTOs\PayoutResponse;
use App\Models\Payout;

interface PayoutProviderInterface
{
    /**
     * Send payout to external payment provider.
     *
     * @throws \App\Exceptions\PayoutProviderTimeoutException
     * @throws \App\Exceptions\PayoutProviderException
     */
    public function sendPayout(Payout $payout): PayoutResponse;

    /**
     * Look up the real transfer status from provider by idempotency key.
     */
    public function getTransferStatus(string $idempotencyKey): PayoutResponse;
}
