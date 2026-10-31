<?php

namespace App\People;

use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/**
 * The notice shown after adding a person (or asking a new link): "Send by WhatsApp" with a ready message,
 * and the link to copy. Used by the "People" screen and by the drivers screen.
 */
class AccessNotice
{
    public static function send(Tenant $tenant, User $user, ?string $url): void
    {
        if ($url === null) {
            Notification::make()->success()->title(__('app.people.already_has_access', ['name' => $user->name]))->send();

            return;
        }

        $message = __('app.people.whatsapp_message', ['name' => $user->name, 'company' => $tenant->name, 'link' => $url]);

        Notification::make()
            ->success()
            ->persistent()
            ->title(__('app.people.access_ready', ['name' => $user->name]))
            // No long technical link on screen: the buttons send it or copy it.
            ->body(filled($user->email) ? __('app.people.access_ready_email') : __('app.people.access_ready_body'))
            ->actions([
                Action::make('whatsapp')
                    ->label(__('app.people.send_whatsapp'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->color('success')
                    ->url(PeopleService::whatsappUrl($user->phone, $message), shouldOpenInNewTab: true),
                Action::make('copy')
                    ->label(__('app.people.copy_link'))
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->color('gray')
                    // Without a secure page (no clipboard), the link opens in a box to copy by hand.
                    ->alpineClickHandler('navigator.clipboard ? navigator.clipboard.writeText('.Js::from($url).').then(() => $tooltip('.Js::from(__('app.people.link_copied')).')) : window.prompt('.Js::from(__('app.people.copy_link')).', '.Js::from($url).')'),
            ])
            ->send();
    }
}
