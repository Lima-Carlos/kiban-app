<?php

namespace App\Notices;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A warning in the bell of one company. The texts are translation keys, written in the language of
 * each person when sent. Clicking it opens the screen given in $url.
 */
class Notice extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    public function __construct(
        public int $tenantId,
        public string $titleKey,
        public array $titleParams = [],
        public ?string $bodyKey = null,
        public array $bodyParams = [],
        public ?string $url = null,
        public string $icon = 'heroicon-o-bell',
        public string $color = 'info',
    ) {}

    /**
     * Names and dates are written in the language of who receives it: an enum gives its label and a
     * closure is called at that moment (e.g. a date in the person's format).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function resolve(array $params): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof Closure => $value(),
            $value instanceof HasLabel => $value->getLabel(),
            default => $value,
        }, $params);
    }

    /**
     * Only the path: the same warning opens on the company's own address (routines run without one).
     */
    public static function path(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title(__($this->titleKey, static::resolve($this->titleParams)))
            ->body($this->bodyKey !== null ? __($this->bodyKey, static::resolve($this->bodyParams)) : null)
            ->icon($this->icon)
            ->iconColor($this->color);

        if ($this->url !== null) {
            $notification->actions([
                Action::make('open')
                    ->label(__('app.notices.open'))
                    ->url(static::path($this->url))
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
