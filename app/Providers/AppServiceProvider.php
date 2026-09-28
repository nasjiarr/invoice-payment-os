<?php

namespace App\Providers;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateways\MockPaymentGateway;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, function ($app) {
            $driver = config('payment.default', 'mock');

            return match ($driver) {
                'mock' => $app->make(MockPaymentGateway::class),
                default => $app->make(MockPaymentGateway::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Number::useCurrency(config('app.currency', 'IDR'));
        Number::useLocale(config('app.locale', 'id'));
    }
}
