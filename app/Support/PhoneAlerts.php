<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Alerts on the person's phone (web push, free): they arrive even with the app closed on Android; on the
 * iPhone, only with the app installed on the home screen (Apple's rule). Phones that no longer exist are
 * forgotten. Without the server's keys (kiban.push), nothing is sent and nothing breaks.
 */
class PhoneAlerts
{
    public function enabled(): bool
    {
        return filled(config('kiban.push.public_key')) && filled(config('kiban.push.private_key'));
    }

    /** Keeps (or renews) a phone of the person. */
    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken): void
    {
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            ['user_id' => $user->getKey(), 'endpoint' => $endpoint, 'public_key' => $publicKey, 'auth_token' => $authToken],
        );
    }

    /** Sends to every phone of the person in this company. Returns how many received it. */
    public function send(User $user, string $title, string $body, ?string $url = null, ?string $tag = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $phones = PushSubscription::query()->where('user_id', $user->getKey())->get();

        if ($phones->isEmpty()) {
            return 0;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config('kiban.push.subject'),
            'publicKey' => config('kiban.push.public_key'),
            'privateKey' => config('kiban.push.private_key'),
        ]], ['TTL' => 3600], 10);

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($phones as $phone) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $phone->endpoint,
                'publicKey' => $phone->public_key,
                'authToken' => $phone->auth_token,
            ]), $payload);
        }

        $sent = 0;

        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
            }
        }

        return $sent;
    }
}
