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

        expect(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('is indistinguishable from a pre-success timeout at the moment it happens', function () {
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
        mode('timeout_after_success');

        try {
            send('payout:99');
        } catch (ProviderTimeoutException) {
        }

        mode('success');
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

describe('random mode', function () {
    function weights(array $weights): void
    {
        config(['revenue.provider.random_weights' => $weights]);
    }

    it('always succeeds when every other outcome is weighted to zero', function () {
        mode('random');
        weights(['success' => 1, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'timeout_before_success' => 0]);

        $result = send();

        expect($result->state)->toBe(ProviderPaymentState::Settled)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('always fails permanently when weighted entirely toward it', function () {
        mode('random');
        weights(['success' => 0, 'permanent_failure' => 1, 'timeout_after_success' => 0, 'timeout_before_success' => 0]);

        expect(fn () => send())->toThrow(ProviderPermanentFailureException::class);

        expect(DB::table('mock_provider_payments')->count())->toBe(0);
    });

    it('always times out after moving the money when weighted entirely toward it', function () {
        mode('random');
        weights(['success' => 0, 'permanent_failure' => 0, 'timeout_after_success' => 1, 'timeout_before_success' => 0]);

        expect(fn () => send())->toThrow(ProviderTimeoutException::class);

        expect(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('always times out before moving any money when weighted entirely toward it', function () {
        mode('random');
        weights(['success' => 0, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'timeout_before_success' => 1]);

        expect(fn () => send())->toThrow(ProviderTimeoutException::class);

        expect(DB::table('mock_provider_payments')->count())->toBe(0);
    });

    it('falls back to success if the weights are misconfigured to sum to zero', function () {
        mode('random');
        weights(['success' => 0, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'timeout_before_success' => 0]);

        $result = send();

        expect($result->state)->toBe(ProviderPaymentState::Settled);
    });

    it('produces more than one outcome across many calls at the default weights', function () {
        mode('random');

        $outcomes = [];

        foreach (range(1, 100) as $i) {
            try {
                send("payout:random:{$i}");
                $outcomes[] = 'success';
            } catch (ProviderPermanentFailureException) {
                $outcomes[] = 'permanent_failure';
            } catch (ProviderTimeoutException) {
                $outcomes[] = 'timeout';
            }
        }

        expect(count(array_unique($outcomes)))->toBeGreaterThan(1);
    });

    it('never rolls the dice twice for the same idempotency key', function () {
        mode('random');
        weights(['success' => 1, 'permanent_failure' => 0, 'timeout_after_success' => 0, 'timeout_before_success' => 0]);

        $first = send('payout:idempotent-random');

        weights(['success' => 0, 'permanent_failure' => 1, 'timeout_after_success' => 0, 'timeout_before_success' => 0]);

        $second = send('payout:idempotent-random');

        expect($second->wasAlreadyProcessed)->toBeTrue()
            ->and($second->reference)->toBe($first->reference)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });
});
