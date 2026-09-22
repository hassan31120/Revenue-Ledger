<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Enums\PayoutStatus;
use App\Filament\Resources\InstructorBalanceResource;
use App\Filament\Resources\PayoutResource;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Payout;
use App\Models\Subscription;
use App\Models\User;

use function Pest\Livewire\livewire;

// Signed in for every test in this file. Done here rather than via a chained
// ->actingAs(...), because the argument to that would be evaluated while tests
// are still being collected — before the application exists.
beforeEach(fn () => test()->actingAs(User::factory()->create()));

function instructorWithBalance(int $grossMinor = 10000): Instructor
{
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->for($instructor)->create();

    $subscription = Subscription::factory()
        ->grossMinor($grossMinor)->platformFeeBps(3000)
        ->withCourses([$course])->create();

    app(AllocateSubscriptionRevenue::class)->handle($subscription);

    return $instructor;
}

describe('access', function () {
    it('sends a guest to the login screen', function () {
        auth()->logout();

        $this->get('/admin/instructor-balances')->assertRedirect('/admin/login');
        $this->get('/admin/payouts')->assertRedirect('/admin/login');
    });

    it('redirects the site root to the admin panel', function () {
        auth()->logout();

        $this->get('/')->assertRedirect('/admin');
    });
});

describe('instructor balances screen', function () {
    it('lists each instructor with earned, paid and outstanding', function () {
        $instructor = instructorWithBalance();

        livewire(InstructorBalanceResource\Pages\ListInstructorBalances::class)
            ->assertOk()
            ->assertCanSeeTableRecords(InstructorBalance::all())
            ->assertSee($instructor->name)
            // Minor units are rendered as money at the edge, not stored that way.
            ->assertSee('EGP 70.00');
    });

    it('flags an instructor who owes the platform', function () {
        $instructor = instructorWithBalance();

        InstructorBalance::where('instructor_id', $instructor->id)
            ->update(['outstanding_minor' => -11025]);

        livewire(InstructorBalanceResource\Pages\ListInstructorBalances::class)
            ->assertOk()
            ->assertSee('-EGP 110.25')
            ->assertSee('Owes the platform');
    });

    it('is read only', function () {
        expect(InstructorBalanceResource::canCreate())->toBeFalse()
            ->and(InstructorBalanceResource::canEdit(new InstructorBalance))->toBeFalse()
            ->and(InstructorBalanceResource::canDelete(new InstructorBalance))->toBeFalse()
            ->and(InstructorBalanceResource::canDeleteAny())->toBeFalse();
    });

    it('exposes no create or edit route at all', function () {
        expect(array_keys(InstructorBalanceResource::getPages()))->toBe(['index']);
    });
});

describe('payouts screen', function () {
    it('lists payouts with status, reference and timestamps', function () {
        $instructor = instructorWithBalance();

        $payout = Payout::create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 7000,
            'currency' => 'EGP',
            'status' => PayoutStatus::Paid,
            'provider_idempotency_key' => 'po_screen_test',
            'provider_reference' => 'mockpay_screen',
            'ledger_cutoff_id' => 1,
            'completed_at' => now(),
        ]);

        livewire(PayoutResource\Pages\ListPayouts::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$payout])
            ->assertSee($instructor->name)
            ->assertSee('EGP 70.00')
            ->assertSee('Paid')
            ->assertSee('mockpay_screen');
    });

    it('shows an unknown payout as needing reconciliation, not as a failure', function () {
        $instructor = instructorWithBalance();

        Payout::create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 7000,
            'currency' => 'EGP',
            'status' => PayoutStatus::Unknown,
            'provider_idempotency_key' => 'po_unknown_screen',
            'ledger_cutoff_id' => 1,
        ]);

        livewire(PayoutResource\Pages\ListPayouts::class)
            ->assertOk()
            ->assertSee('Unknown — needs reconciliation');

        // An unresolved payout must never be presented as a failure.
        expect(PayoutStatus::Unknown->color())->toBe('warning')
            ->and(PayoutStatus::Unknown->color())->not->toBe(PayoutStatus::Failed->color());
    });

    it('badges unresolved payouts in the navigation', function () {
        $instructor = instructorWithBalance();

        expect(PayoutResource::getNavigationBadge())->toBeNull();

        Payout::create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 7000,
            'currency' => 'EGP',
            'status' => PayoutStatus::Unknown,
            'provider_idempotency_key' => 'po_badge',
            'ledger_cutoff_id' => 1,
        ]);

        expect(PayoutResource::getNavigationBadge())->toBe('1')
            ->and(PayoutResource::getNavigationBadgeColor())->toBe('warning');
    });

    it('is read only', function () {
        expect(PayoutResource::canCreate())->toBeFalse()
            ->and(PayoutResource::canEdit(new Payout))->toBeFalse()
            ->and(PayoutResource::canDelete(new Payout))->toBeFalse()
            ->and(PayoutResource::canDeleteAny())->toBeFalse()
            ->and(array_keys(PayoutResource::getPages()))->toBe(['index']);
    });
});
