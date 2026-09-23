<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Actions\ClaimInstructorPayout;
use App\Enums\PayoutStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payout;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function instructorOwed7000(): Instructor
{
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->for($instructor)->create();

    $subscription = Subscription::factory()
        ->grossMinor(10000)->platformFeeBps(3000)
        ->withCourses([$course])->create();

    app(AllocateSubscriptionRevenue::class)->handle($subscription);

    return $instructor;
}

function workerB()
{
    return DB::connection('mysql_worker_b');
}

function openPayoutRow(int $instructorId, string $key): array
{
    return [
        'instructor_id' => $instructorId,
        'amount_minor' => 7000,
        'currency' => 'EGP',
        'status' => PayoutStatus::Pending->value,
        'provider_idempotency_key' => $key,
        'ledger_cutoff_id' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('uses two genuinely distinct database sessions', function () {
    $a = DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $b = workerB()->selectOne('SELECT CONNECTION_ID() AS id')->id;

    expect($a)->not->toBe($b);
});

it('lets only one of two workers open a payout for the same instructor', function () {
    $instructor = instructorOwed7000();

    DB::table('payouts')->insert(openPayoutRow($instructor->id, 'key_worker_a'));

    expect(fn () => workerB()->table('payouts')->insert(openPayoutRow($instructor->id, 'key_worker_b')))
        ->toThrow(QueryException::class);

    expect(Payout::count())->toBe(1);
});

it('still refuses the second payout when both workers skip every application check', function () {
    $instructor = instructorOwed7000();

    $results = [];

    foreach (['mysql', 'mysql_worker_b'] as $i => $connection) {
        try {
            DB::connection($connection)->table('payouts')
                ->insert(openPayoutRow($instructor->id, "raw_key_{$i}"));
            $results[] = 'inserted';
        } catch (QueryException) {
            $results[] = 'rejected';
        }
    }

    expect($results)->toBe(['inserted', 'rejected'])
        ->and(Payout::count())->toBe(1);
});

it('serialises two workers on the same instructor with a row lock', function () {
    $instructor = instructorOwed7000();

    workerB()->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        DB::table('instructor_balances')
            ->where('instructor_id', $instructor->id)
            ->lockForUpdate()
            ->first();

        expect(fn () => workerB()->table('instructor_balances')
            ->where('instructor_id', $instructor->id)
            ->lockForUpdate()
            ->first()
        )->toThrow(QueryException::class);
    } finally {
        DB::rollBack();
    }

    $row = workerB()->table('instructor_balances')->where('instructor_id', $instructor->id)->first();

    expect((int) $row->outstanding_minor)->toBe(7000);
});

it('produces exactly one payout when the claim action is raced', function () {
    $instructor = instructorOwed7000();

    $claim = app(ClaimInstructorPayout::class);

    $first = $claim->handle($instructor->id, 1);
    $second = $claim->handle($instructor->id, 1);
    $third = $claim->handle($instructor->id, 1);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($third)->toBeNull()
        ->and(Payout::count())->toBe(1);
});

it('releases the instructor only once the payout reaches a terminal state', function () {
    $instructor = instructorOwed7000();

    DB::table('payouts')->insert(openPayoutRow($instructor->id, 'key_open'));

    expect(fn () => workerB()->table('payouts')->insert(openPayoutRow($instructor->id, 'key_second')))
        ->toThrow(QueryException::class);

    DB::table('payouts')->where('provider_idempotency_key', 'key_open')
        ->update(['status' => PayoutStatus::Paid->value, 'completed_at' => now()]);

    workerB()->table('payouts')->insert(openPayoutRow($instructor->id, 'key_second'));

    expect(Payout::count())->toBe(2)
        ->and(Payout::where('status', PayoutStatus::Pending->value)->count())->toBe(1);
});

it('keeps an unknown payout occupying the slot', function () {
    $instructor = instructorOwed7000();

    DB::table('payouts')->insert([
        ...openPayoutRow($instructor->id, 'key_unknown'),
        'status' => PayoutStatus::Unknown->value,
    ]);

    expect(fn () => workerB()->table('payouts')->insert(openPayoutRow($instructor->id, 'key_after_unknown')))
        ->toThrow(QueryException::class);
});

it('allows unlimited terminal payouts in an instructor history', function () {
    $instructor = instructorOwed7000();

    foreach (range(1, 5) as $i) {
        DB::table('payouts')->insert([
            ...openPayoutRow($instructor->id, 'historic_'.Str::random(8)),
            'status' => $i % 2 === 0 ? PayoutStatus::Paid->value : PayoutStatus::Failed->value,
        ]);
    }

    expect(Payout::count())->toBe(5);

    workerB()->table('payouts')->insert(openPayoutRow($instructor->id, 'current_open'));

    expect(Payout::count())->toBe(6);
});
