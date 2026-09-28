<?php

namespace XLaravel\Payline\Gateways\Qnb;

use Illuminate\Support\ServiceProvider;

class QnbServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('payline')->extend('qnb', function ($app, array $config) {
            return new QnbGateway($config);
        });
    }
}