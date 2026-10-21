<?php

namespace App\Notices;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, keeping the company of the warning (the bell shows only the current company's).
 */
class TenantDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        return parent::buildPayload($notifiable, $notification) + [
            'tenant_id' => $notification instanceof Notice ? $notification->tenantId : null,
        ];
    }
}
