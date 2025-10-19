<?php

namespace Dashed\DashedEcommerceMollie\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedEcommerceMollie\Classes\Mollie;

class MollieSettingsPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Mollie';

    protected string $view = 'dashed-core::settings.pages.default-settings';
    public array $data = [];

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

    public function form(Schema $schema): Schema
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $newSchema = [
                TextEntry::make('label')
                    ->state("Mollie voor {$site['name']}")
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextEntry::make("Mollie is " . (! Customsetting::get('mollie_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->state(Customsetting::get('mollie_connection_error', $site['id'], ''))
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make("mollie_partner_id_{$site['id']}")
                    ->label('Mollie Partner ID')
                    ->maxLength(255),
                TextInput::make("mollie_api_key_{$site['id']}")
                    ->label('Mollie API key')
                    ->maxLength(255),
                Toggle::make("mollie_test_mode_{$site['id']}")
                    ->label('Testmodus activeren'),
            ];

            $tabs[] = Tab::make($site['id'])
                ->label(ucfirst($site['name']))
                ->schema($newSchema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $schema->schema($tabGroups)
            ->statePath('data');
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

        Notification::make()
            ->title('De Mollie instellingen zijn opgeslagen')
            ->success()
            ->send();

        return redirect(MollieSettingsPage::getUrl());
    }
}
