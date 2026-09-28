<?php

namespace XLaravel\Payline\Gateways\Qnb\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Payline\PaylineServiceProvider;
use XLaravel\Payline\Gateways\Qnb\QnbServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaylineServiceProvider::class,
            QnbServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payline.default', 'qnb');
        $app['config']->set('payline.gateways.qnb', [
            'mbr_id' => '5',
            'merchant_id' => 'TEST_MERCHANT',
            'user_name' => 'TEST_USER',
            'password' => 'TEST_PASSWORD',
            'merchant_pass' => 'TEST_MERCHANT_PASS',
            'test_mode' => true,
            'lang' => 'TR',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/x-laravel/payline/database/migrations');
    }
}
