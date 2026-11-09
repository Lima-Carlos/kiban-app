<?php

namespace App\Filament\Pages;

use App\Digest\WeeklyDigest;
use App\Licensing\CoreClient;
use App\Licensing\CoreUnavailableException;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

/**
 * "Pergunte ao seu sistema" (Company group, owner and administrators): a question in plain words, answered by
 * Claude through the Core from the numbers of the last weeks of every system (the same as the weekly summary,
 * without names). Ready but off: config kiban.ask_enabled (KIBAN_ASK_ENABLED) here, and the switch in the Core.
 */
class AskPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'empresa/pergunte';

    protected static ?int $navigationSort = -4;

    /** Weeks of numbers sent with each question. */
    public const WEEKS = 6;

    /** @var array<string, mixed> */
    public ?array $data = ['question' => ''];

    public ?string $answer = null;

    public ?string $asked = null;

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        return (bool) config('kiban.ask_enabled') && WeeklyDigestPage::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('digest.ask.title');
    }

    public function getTitle(): string
    {
        return __('digest.ask.title');
    }

    public function getSubheading(): ?string
    {
        return __('digest.ask.help');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Textarea::make('question')
                ->hiddenLabel()
                ->placeholder(__('digest.ask.placeholder'))
                ->rows(2)
                ->required()
                ->minLength(3)
                ->maxLength(500),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('ask')
                ->footer([Actions::make([Action::make('ask')->label(__('digest.ask.submit'))->icon(Heroicon::OutlinedSparkles)->submit('ask')])]),
            Section::make(fn (): string => (string) $this->asked)
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->visible(fn (): bool => $this->answer !== null)
                ->schema([
                    Text::make(fn (): string => (string) $this->answer),
                    Text::make(__('digest.ask.footer'))->color('gray')->size('xs'),
                ]),
        ]);
    }

    public function ask(): void
    {
        abort_unless(static::canAccess(), 403);

        $question = trim((string) $this->form->getState()['question']);
        $tenant = app(TenantContext::class)->get();
        $digest = app(WeeklyDigest::class);
        $last = WeeklyDigest::weekBefore(CarbonImmutable::now(FilamentTimezone::get()));
        $weeks = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $built = $digest->build($tenant, $last->subWeeks($i), auth()->user());
            $weeks[] = [
                'week' => $digest->weekLabel($built['from']),
                'sections' => array_map(fn (array $section): array => ['name' => $section['name'], 'lines' => array_slice($section['lines'], 0, 12)], $built['sections']),
            ];
        }

        try {
            $answer = app(CoreClient::class)->ask($tenant->subdomain, ['question' => $question, 'locale' => app()->getLocale(), 'weeks' => $weeks]);
        } catch (CoreUnavailableException) {
            $answer = null;
        }

        $this->asked = $question;
        $this->answer = $answer ?? __('digest.ask.unavailable');
    }
}
