<?php

namespace Qubiqx\QcommerceEcommerceMollie\Filament\Pages\Settings;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Qubiqx\QcommerceCore\Classes\Sites;
use Qubiqx\QcommerceCore\Models\Customsetting;
use Qubiqx\QcommerceEcommerceMollie\Classes\Mollie;

class MollieSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Mollie';

    protected static string $view = 'qcommerce-core::settings.pages.default-settings';

    public function mount(): void
    {
        $formData = [];
        $sites = Sites::getSites();
        foreach ($sites as $site) {
            $formData["mollie_partner_id_{$site['id']}"] = Customsetting::get('mollie_partner_id', $site['id']);
            $formData["mollie_api_key_{$site['id']}"] = Customsetting::get('mollie_api_key', $site['id']);
            $formData["mollie_test_mode_{$site['id']}"] = Customsetting::get('mollie_test_mode', $site['id'], false) ? true : false;
            $formData["mollie_connected_{$site['id']}"] = Customsetting::get('mollie_connected', $site['id']);
            $formData["mollie_connection_error_{$site['id']}"] = Customsetting::get('mollie_connection_error', $site['id']);
        }

        $this->form->fill($formData);
    }

    protected function getFormSchema(): array
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $schema = [
                Placeholder::make('label')
                    ->label("Mollie voor {$site['name']}")
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                Placeholder::make('label')
                    ->label("Mollie is " . (! Customsetting::get('mollie_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->content(Customsetting::get('mollie_connection_error', $site['id'], ''))
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make("mollie_partner_id_{$site['id']}")
                    ->label('Mollie Partner ID')
                ->rules([
                    'max:255',
                ]),
                TextInput::make("mollie_api_key_{$site['id']}")
                    ->label('Mollie API key')
                ->rules([
                    'max:255',
                ]),
                Toggle::make("mollie_test_mode_{$site['id']}")
                    ->label('Testmodus activeren'),
            ];

            $tabs[] = Tab::make($site['id'])
                ->label(ucfirst($site['name']))
                ->schema($schema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $tabGroups;
    }

    public function submit()
    {
        $sites = Sites::getSites();

        foreach ($sites as $site) {
            Customsetting::set('mollie_partner_id', $this->form->getState()["mollie_partner_id_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_api_key', $this->form->getState()["mollie_api_key_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_test_mode', $this->form->getState()["mollie_test_mode_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_connected', Mollie::isConnected($site['id']), $site['id']);

            if (Customsetting::get('mollie_connected', $site['id'])) {
                Mollie::syncPaymentMethods($site['id']);
            }
        }

        $this->notify('success', 'De Mollie instellingen zijn opgeslagen');

        return redirect(MollieSettingsPage::getUrl());
    }
}
