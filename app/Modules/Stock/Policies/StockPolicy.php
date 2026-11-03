<?php

namespace App\Modules\Stock\Policies;

use App\Models\User;
use App\Modules\ModulePolicy;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockAccess;
use App\Modules\Stock\StockModule;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock screens: the manager changes, the viewer looks; technicians use only "My stock". A new place counts in
 * the plan limit (places).
 */
class StockPolicy extends ModulePolicy
{
    protected string $module = StockModule::KEY;

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->denied()) {
            return $access;
        }

        return app(StockAccess::class)->seesEverything($user) ? Response::allow() : Response::deny(__('stock.denied.manager'));
    }

    public function create(User $user): Response
    {
        $this->limit = null;
        $this->model = null;

        return $this->manager($user, parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->manager($user, parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->manager($user, parent::delete($user, $record));
    }

    public function deleteAny(User $user): Response
    {
        return $this->manager($user, parent::deleteAny($user));
    }

    /** New places stop at the plan limit (warehouses and technicians together). */
    public function createLocation(User $user): Response
    {
        $this->limit = StockModule::LIMIT_LOCATIONS;
        $this->model = Location::class;

        return $this->manager($user, parent::create($user));
    }

    protected function countForLimit(): int
    {
        return Location::query()->active()->count();
    }

    protected function manager(User $user, Response $response): Response
    {
        if ($response->denied()) {
            return $response;
        }

        return app(StockAccess::class)->isManager($user) ? Response::allow() : Response::deny(__('stock.denied.manager'));
    }
}
