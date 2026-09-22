<?php

declare(strict_types=1);

use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;
use App\Services\Payments\PaymentProvider;
use Illuminate\Support\Facades\DB;

function provider(): PaymentProvider
{
    return app(PaymentProvider::class);
}

function mode(string $mode): void
{
    config(['revenue.provider.mock_mode' => $mode]);
}

function send(string $key = 'payout:1', int $amountMinor = 5000)
{
    return provider()->sendPayout($key, $amountMinor, 'EGP', 'acct_test');
}

describe('success', function () {
    it('moves the money and returns a reference', function () {
        mode('success');

        $result = send();

        expect($result->state)->toBe(ProviderPaymentState::Settled)
            ->and($result->reference)->toStartWith('mockpay_')
            ->and($result->wasAlreadyProcessed)->toBeFalse()
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('reports a settled payment when asked afterwards', function () {
        mode('success');
        $result = send();

        $status = provider()->getPaymentStatus($result->reference);

        expect($status->isSettled())->toBeTrue()
            ->and($status->amountMinor)->toBe(5000);
    });
});

describe('permanent failure', function () {
    it('moves no money and says so definitively', function () {
        mode('permanent_failure');

        expect(fn () => send())->toThrow(ProviderPermanentFailureException::class);

        expect(DB::table('mock_provider_payments')->count())->toBe(0);
    });

    it('reports the payment as never having existed', function () {
        mode('permanent_failure');

        try {
            send();
        } catch (ProviderPermanentFailureException) {
        }

        expect(provider()->getPaymentStatus('payout:1')->state)
            ->toBe(ProviderPaymentState::NotFound);
    });
});

describe('timeout before the money moved', function () {
    it('moves no money and leaves the caller without an answer', function () {
        mode('timeout_before_success');

        expect(fn () => send())->toThrow(ProviderTimeoutException::class);

        expect(DB::table('mock_provider_payments')->count())->toBe(0);
    });

    it('can be resolved as not-found by a status lookup', function () {
        mode('timeout_before_success');

        try {
            send();
        } catch (ProviderTimeoutException) {
        }

        expect(provider()->getPaymentStatus('payout:1')->state)
            ->toBe(ProviderPaymentState::NotFound);
    });
});

describe('timeout AFTER the money moved', function () {
    it('has already moved the money when the caller sees the timeout', function () {
        mode('timeout_after_success');

        expect(fn () => send())->toThrow(ProviderTimeoutException::class);

        // The caller was told nothing, but the money is gone.
        expect(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('is indistinguishable from a pre-success timeout at the moment it happens', function () {
        // Both modes raise the same exception type carrying the same key. The
        // caller genuinely cannot tell them apart, which is exactly why a timeout
        // must never be recorded as a failure.
        mode('timeout_after_success');
        $after = null;
        try {
            send('payout:after');
        } catch (ProviderTimeoutException $e) {
            $after = $e;
        }

        mode('timeout_before_success');
        $before = null;
        try {
            send('payout:before');
        } catch (ProviderTimeoutException $e) {
            $before = $e;
        }

        expect($after::class)->toBe($before::class)
            ->and($after->idempotencyKey)->toBe('payout:after')
            ->and($before->idempotencyKey)->toBe('payout:before');
    });

    it('tells the truth on a later status lookup by idempotency key', function () {
        mode('timeout_after_success');

        try {
            send('payout:77');
        } catch (ProviderTimeoutException) {
        }

        // The caller holds only the key it sent — never a reference.
        $status = provider()->getPaymentStatus('payout:77');

        expect($status->isSettled())->toBeTrue()
            ->and($status->amountMinor)->toBe(5000)
            ->and($status->reference)->toStartWith('mockpay_');
    });
});

describe('idempotency keys', function () {
    it('returns the original payment instead of paying twice', function () {
        mode('success');

        $first = send('payout:42');
        $second = send('payout:42');

        expect($second->reference)->toBe($first->reference)
            ->and($second->wasAlreadyProcessed)->toBeTrue()
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('honours a key that was accepted during a timeout', function () {
        // The critical retry path: the provider took the money, we never heard
        // back, and the job retries with the same key.
        mode('timeout_after_success');

        try {
            send('payout:99');
        } catch (ProviderTimeoutException) {
        }

        mode('success'); // the network recovers
        $retry = send('payout:99');

        expect($retry->wasAlreadyProcessed)->toBeTrue()
            ->and(DB::table('mock_provider_payments')->count())->toBe(1)
            ->and((int) DB::table('mock_provider_payments')->sum('amount_minor'))->toBe(5000);
    });

    it('does not pay twice even when the mode would otherwise fail', function () {
        mode('success');
        $first = send('payout:7');

        mode('permanent_failure');
        $second = send('payout:7');

        // Already settled wins: the provider reports the original payment rather
        // than re-evaluating it.
        expect($second->reference)->toBe($first->reference);
    });

    it('treats different keys as different payments', function () {
        mode('success');

        send('payout:1');
        send('payout:2');

        expect(DB::table('mock_provider_payments')->count())->toBe(2);
    });
});

it('rejects an unknown mode rather than guessing', function () {
    mode('wat');

    expect(fn () => send())->toThrow(InvalidArgumentException::class);
});
