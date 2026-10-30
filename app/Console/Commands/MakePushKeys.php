<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Makes the server's key pair for the alerts on the phone (web push). Run once per server and copy the two lines
 * to the .env: changing them later makes every phone subscribe again.
 */
class MakePushKeys extends Command
{
    protected $signature = 'kiban:push-keys';

    protected $description = 'Make the key pair for the alerts on the phone (web push)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('KIBAN_PUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('KIBAN_PUSH_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
