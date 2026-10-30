<?php

namespace App\Filament\Pages;

use App\Models\CompanyProfile;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "Company identity" (owners and administrators): the logo, the color and the company's data that go on what
 * its customers receive (work order reports, quotes). Shared by every system, in the "Company" group.
 */
class CompanyProfilePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $slug = 'empresa/identidade';

    protected static ?int $navigationSort = -1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        return app(TenantContext::class)->current() !== null && (bool) auth()->user()?->canManageTenant();
    }

    public static function getNavigationLabel(): string
    {
        return __('app.company_profile.title');
    }

    public function getTitle(): string
    {
        return __('app.company_profile.title');
    }

    public function getSubheading(): ?string
    {
        return __('app.company_profile.help');
    }

    public function mount(): void
    {
        $profile = CompanyProfile::current();

        $this->form->fill(array_merge($profile->only(['logo_path', 'legal_name', 'document', 'phone', 'email', 'website', 'address', 'report_footer']), [
            'color' => $profile->color(),
            'legal_name' => $profile->name(),
        ]));
    }

    public function form(Schema $schema): Schema
    {
        $tenant = app(TenantContext::class)->get();

        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('app.company_profile.look'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        FileUpload::make('logo_path')
                            ->label(__('app.company_profile.logo'))
                            ->helperText(__('app.company_profile.logo_help'))
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->disk('local')
                            ->visibility('private')
                            ->directory('tenants/'.$tenant->getKey().'/brand')
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('600')
                            ->imageResizeTargetHeight('300')
                            ->maxSize(2048),
                        ColorPicker::make('color')
                            ->label(__('app.company_profile.color'))
                            ->helperText(__('app.company_profile.color_help'))
                            ->regex('/^#[0-9a-fA-F]{6}$/'),
                    ]),
                Section::make(__('app.company_profile.data'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        TextInput::make('legal_name')->label(__('app.company_profile.legal_name'))->maxLength(255)->required(),
                        TextInput::make('document')->label(__('app.company_profile.document'))->maxLength(40),
                        TextInput::make('phone')->label(__('app.company_profile.phone'))->tel()->maxLength(30),
                        TextInput::make('email')->label(__('app.company_profile.email'))->email()->maxLength(255),
                        TextInput::make('website')->label(__('app.company_profile.website'))->maxLength(255),
                        TextInput::make('address')->label(__('app.company_profile.address'))->maxLength(255),
                        Textarea::make('report_footer')
                            ->label(__('app.company_profile.footer'))
                            ->helperText(__('app.company_profile.footer_help'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('app.company_profile.save'))->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $profile = CompanyProfile::current();
        $profile->fill($this->form->getState())->save();

        Notification::make()->success()->title(__('app.company_profile.saved'))->send();
    }
}
