<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AllocateSubscriptionRevenue;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AllocateRevenue extends Command
{
    protected $signature = 'revenue:allocate
                            {--subscription= : Allocate one subscription by id}
                            {--limit=0 : Stop after this many subscriptions (0 = no limit)}';

    protected $description = 'Allocate subscription revenue to instructors and the platform';

    public function handle(AllocateSubscriptionRevenue $allocate): int
    {
        $processed = 0;
        $allocatedMinor = 0;
        $limit = (int) $this->option('limit');

        $this->pendingSubscriptions()->chunkById(200, function ($subscriptions) use (
            $allocate, &$processed, &$allocatedMinor, $limit
        ) {
            foreach ($subscriptions as $subscription) {
                $split = $allocate->handle($subscription);

                $processed++;
                $allocatedMinor += $split->instructorPoolMinor();

                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
            }
        });

        $this->info(sprintf(
            'Allocated %d subscription(s); %s credited to instructors.',
            $processed,
            Money::format($allocatedMinor),
        ));

        return self::SUCCESS;
    }

    private function pendingSubscriptions(): Builder
    {
        $query = Subscription::query();

        if ($id = $this->option('subscription')) {
            return $query->whereKey((int) $id);
        }

        return $query->whereNotExists(
            fn ($sub) => $sub->selectRaw('1')
                ->from('revenue_allocations')
                ->whereColumn('revenue_allocations.subscription_id', 'subscriptions.id')
        );
    }
}
