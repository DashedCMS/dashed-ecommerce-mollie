<?php

namespace Dashed\DashedEcommerceMollie;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;
<<<<<<< HEAD
=======
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
>>>>>>> filamentv3

class DashedEcommerceMollieServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-mollie';

    public function bootingPackage()
    {
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(SyncMolliePaymentMethodsCommand::class)->daily();
        });
    }

    public function configurePackage(Package $package): void
    {
        cms()->builder(
            'settingPages',
            array_merge(cms()->builder('settingPages'), [
                'mollie' => [
                    'name' => 'Mollie',
                    'description' => 'Link Mollie aan je webshop',
                    'icon' => 'banknotes',
                    'page' => MollieSettingsPage::class,
                ],
            ])
        );

        ecommerce()->builder(
            'paymentServiceProviders',
            array_merge(ecommerce()->builder('paymentServiceProviders'), [
                'mollie' => [
                    'name' => 'Mollie',
                    'class' => Mollie::class,
                ],
            ])
        );

        $package
            ->name('dashed-ecommerce-mollie')
            ->hasCommands([
                SyncMolliePaymentMethodsCommand::class,
            ]);
    }
}
