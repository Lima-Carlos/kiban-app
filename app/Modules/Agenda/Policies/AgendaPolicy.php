<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules of the Agenda screens: who may see them (viewRoles) and who may change them (editRoles).
 */
abstract class AgendaPolicy extends ModulePolicy
{
    protected string $module = AgendaModule::KEY;

    /** @var array<int, AgendaRole> */
    protected array $viewRoles = [AgendaRole::Manager, AgendaRole::Desk, AgendaRole::Viewer];

    /** @var array<int, AgendaRole> */
    protected array $editRoles = [AgendaRole::Manager];

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->allowed() && ! in_array(app(AgendaAccess::class)->role($user), $this->viewRoles, true)) {
            return Response::deny(__('agenda.denied.no_screen'));
        }

        return $access;
    }

    public function create(User $user): Response
    {
        return $this->editors($user, fn (): Response => parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->editors($user, fn (): Response => parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->editors($user, fn (): Response => parent::delete($user, $record));
    }

    public function deleteAny(User $user): Response
    {
        return $this->editors($user, fn (): Response => parent::deleteAny($user));
    }

    /**
     * @param  callable(): Response  $next
     */
    protected function editors(User $user, callable $next): Response
    {
        $response = $next();

        if ($response->allowed() && ! in_array(app(AgendaAccess::class)->role($user), $this->editRoles, true)) {
            return Response::deny(__('agenda.denied.manager'));
        }

        return $response;
    }
}
