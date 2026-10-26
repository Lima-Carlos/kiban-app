<?php

namespace App\Tenancy;

use Illuminate\Http\Request;

/**
 * Single address with the company in the path: /padaria/ponto/apuracao is handled as /ponto/apuracao of "padaria".
 *
 * The company becomes the "base" of the request, like an app installed in a folder: the routes see the path
 * without it, and every address the app generates (links, forms, Livewire, redirects) carries it again.
 * Done at the very entrance of every visit (App\Http\Kernel), before any middleware.
 */
class TenantPath
{
    public function __construct(protected TenantResolver $resolver) {}

    public function apply(Request $request): Request
    {
        $path = $request->getPathInfo();
        $slug = $this->resolver->slugFromPath($path);

        if ($slug === null) {
            return $request;
        }

        $script = '/'.$slug.'/index.php';
        $server = [
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script,
            'SCRIPT_FILENAME' => public_path('index.php'),
        ];

        // "/padaria" alone is the company's start page ("/padaria/").
        if (rtrim($path, '/') === '/'.$slug) {
            $query = $request->server->get('QUERY_STRING');
            $server['REQUEST_URI'] = '/'.$slug.'/'.(filled($query) ? '?'.$query : '');
        }

        $tenantRequest = $request->duplicate(server: array_merge($request->server->all(), $server));
        $tenantRequest->attributes->set(TenantResolver::ATTRIBUTE, $slug);

        return $tenantRequest;
    }
}
