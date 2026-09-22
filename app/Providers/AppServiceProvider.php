<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The only binding in the application. Swapping in a real payment rail is
        // a one-line change here; nothing in the financial core knows the
        // difference, because nothing in it names a concrete provider.
        $this->app->bind(PaymentProvider::class, MockPaymentProvider::class);
    }

    public function boot(): void
    {
        // A silently-ignored mass assignment in a financial model would be a money
        // bug, not a convenience. Fail loudly instead.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
