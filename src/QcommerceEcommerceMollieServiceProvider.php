<?php

namespace Qubiqx\QcommerceEcommerceMollie;

use Filament\PluginServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Qubiqx\QcommerceEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;
use Spatie\LaravelPackageTools\Package;

class QcommerceEcommerceMollieServiceProvider extends PluginServiceProvider
{
    public static string $name = 'qcommerce-ecommerce-paynl';

    public function bootingPackage()
    {
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
//            $schedule->command(SyncPayNLPaymentMethods::class)->daily();
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
                    'icon' => 'cash',
                    'page' => MollieSettingsPage::class,
                ],
            ])
        );

        ecommerce()->builder(
            'paymentServiceProviders',
            array_merge(ecommerce()->builder('paymentServiceProviders'), [
                'mollie' => [
                    'name' => 'Mollie',
//                    'class' => PayNL::class,
                ],
            ])
        );

        $package
            ->name('qcommerce-ecommerce-paynl')
            ->hasCommands([
//                SyncPayNLPaymentMethods::class,
            ]);
    }

    protected function getPages(): array
    {
        return array_merge(parent::getPages(), [
            MollieSettingsPage::class,
        ]);
    }
}
