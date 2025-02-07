<?php

namespace Dashed\DashedEcommerceMollie;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;

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
        cms()->registerSettingsPage(MollieSettingsPage::class, 'Mollie', 'banknotes', 'Link Mollie aan je webshop');

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

        cms()->builder('plugins', [
            new DashedEcommerceMolliePlugin(),
        ]);
    }
}
