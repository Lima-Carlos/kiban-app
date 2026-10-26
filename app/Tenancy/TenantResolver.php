<?php

namespace App\Tenancy;

use Illuminate\Http\Request;

/**
 * Reads the company from the first part of the path, on the single address of the app:
 * app.kibancore.com.br/padaria/ponto/apuracao → "padaria" (the rest of the app sees /ponto/apuracao).
 * Addresses without a company (the app's own pages and files) are "central".
 */
class TenantResolver
{
    /** The request attribute with the company of the path (set by TenantPath). */
    public const ATTRIBUTE = 'kiban_tenant_path';

    /**
     * First parts of the path that belong to the app itself, never to a company (the Core blocks them as company
     * names too).
     */
    public const RESERVED = [
        'entrar', 'auth', 'idioma', 'manifest.webmanifest', 'sw.js', 'livewire', 'storage', 'css', 'js', 'fonts',
        'images', 'build', 'filament', 'vendor', 'favicon.ico', 'robots.txt', 'up', 'api',
    ];

    /** The company name in a path ("/padaria/ponto" → "padaria"), or null. */
    public function slugFromPath(string $path): ?string
    {
        $first = mb_strtolower(explode('/', ltrim($path, '/'))[0] ?? '');

        if ($first === '' || in_array($first, self::RESERVED, true) || str_starts_with($first, 'livewire')) {
            return null;
        }

        // Same format the Core accepts for a company's address.
        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $first) === 1 ? $first : null;
    }

    /** The company of the current request (after TenantPath), or null on the app's own pages. */
    public function slug(Request $request): ?string
    {
        $slug = $request->attributes->get(self::ATTRIBUTE);

        return is_string($slug) ? $slug : null;
    }

    public function isCentral(Request $request): bool
    {
        return $this->slug($request) === null;
    }
}
