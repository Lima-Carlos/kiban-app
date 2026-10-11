<?php

namespace App\Tenancy;

/**
 * Reads the company address from the host name: padaria.kibancore.com.br → "padaria".
 * The central address (APP_URL, e.g. app.kibancore.com.br) is not a company.
 */
class TenantResolver
{
    public function isCentral(string $host): bool
    {
        return mb_strtolower($host) === mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public function subdomain(string $host): ?string
    {
        $host = mb_strtolower($host);

        if ($this->isCentral($host)) {
            return null;
        }

        $suffix = '.'.mb_strtolower((string) config('kiban.tenant_domain'));

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        // One level only, same format the Core accepts.
        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $subdomain) === 1 ? $subdomain : null;
    }
}
