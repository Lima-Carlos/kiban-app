<?php

namespace App\Access;

use App\Models\User;
use App\Modules\Module;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The system (module) the person is using now, chosen with the icons at the top of the menu: the menu and the
 * start page show only that system.
 *
 * - remembered per person and per company (tenant_user.current_module); the first time, the first system;
 * - opening a screen of another system (e.g. a warning of the bell) switches to it by itself;
 * - "?sistema=ponto" on any screen switches too (the icons use it).
 */
class CurrentModule
{
    /** Query string of the icons. */
    public const QUERY = 'sistema';

    protected ?string $key = null;

    /** The visit the key was read for. */
    protected ?int $request = null;

    public function __construct(
        protected AccessManager $access,
        protected TenantContext $context,
    ) {}

    /**
     * The systems this person can open here, in the order of the app.
     *
     * @return array<string, Module>
     */
    public function modules(?User $user = null): array
    {
        $user ??= Auth::user();
        $tenant = $this->context->current();

        return $user instanceof User && $tenant !== null ? $this->access->modulesFor($user, $tenant) : [];
    }

    public function key(): ?string
    {
        if ($this->key !== null && $this->request === spl_object_id(request())) {
            return $this->key;
        }

        $this->key = null;
        $this->request = spl_object_id(request());

        $modules = $this->modules();

        if ($modules === []) {
            return null;
        }

        $wanted = $this->fromRequest(array_keys($modules));
        $stored = $this->stored();

        if ($wanted !== null && $wanted !== $stored) {
            $this->store($wanted);
        }

        return $this->key = $wanted ?? (isset($modules[$stored]) ? $stored : array_key_first($modules));
    }

    public function get(): ?Module
    {
        $key = $this->key();

        return $key !== null ? ($this->modules()[$key] ?? null) : null;
    }

    public function is(string $moduleKey): bool
    {
        return $this->key() === $moduleKey;
    }

    /** The switcher only appears with two systems or more. */
    public function hasChoice(): bool
    {
        return count($this->modules()) > 1;
    }

    /**
     * The system asked by the address: "?sistema=ponto", or the first part of the path ("/ponto/apuracao").
     *
     * @param  array<int, string>  $keys
     */
    protected function fromRequest(array $keys): ?string
    {
        $request = request();
        $asked = (string) $request->query(self::QUERY, '');

        if (in_array($asked, $keys, true)) {
            return $asked;
        }

        $first = explode('/', trim($request->path(), '/'))[0] ?? '';

        return in_array($first, $keys, true) ? $first : null;
    }

    protected function stored(): ?string
    {
        $user = Auth::user();
        $tenant = $this->context->current();

        if (! $user instanceof User || $tenant === null) {
            return null;
        }

        return DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->value('current_module');
    }

    protected function store(string $key): void
    {
        $user = Auth::user();
        $tenant = $this->context->current();

        if ($user instanceof User && $tenant !== null) {
            DB::table('tenant_user')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->update(['current_module' => $key]);
        }
    }
}
