<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\Payout;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutRecorder;
use Illuminate\Support\Facades\DB;

final class SettlePayout
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PayoutRecorder $recorder,
    ) {}

    public function handle(Payout $payout): Payout
    {
        if ($payout->status->isTerminal()) {
            return $payout;
        }

        if (! $this->claim($payout)) {
            return $payout->fresh();
        }

        $account = $payout->instructor()->value('payout_account_ref');

        try {
            $result = $this->provider->sendPayout(
                $payout->provider_idempotency_key,
                $payout->amount_minor,
                $payout->currency,
                $account,
            );

            $this->recorder->recordAttempt($payout, 'send', 'success', $result->reference);
            $this->recorder->markPaid($payout, $result->reference);
        } catch (ProviderTimeoutException $e) {
            $this->recorder->recordAttempt($payout, 'send', 'timeout', null, $e->getMessage());
            $this->recorder->markUnknown($payout, $e->getMessage());
        } catch (ProviderPermanentFailureException $e) {
            $this->recorder->recordAttempt($payout, 'send', 'permanent_failure', null, $e->getMessage());
            $this->recorder->markFailed($payout, $e->getMessage());
        }

        return $payout->fresh();
    }

    private function claim(Payout $payout): bool
    {
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->where('status', PayoutStatus::Pending->value)
            ->update([
                'status' => PayoutStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $payout->status = PayoutStatus::Processing;
            $payout->attempts++;
        }

        return $claimed === 1;
    }
}
