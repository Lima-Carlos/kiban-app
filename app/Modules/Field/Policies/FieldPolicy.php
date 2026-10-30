<?php

namespace App\Modules\Field\Policies;

use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules of the Kiban Campo screens: who may see them (viewRoles) and who may change them (editRoles).
 */
abstract class FieldPolicy extends ModulePolicy
{
    protected string $module = FieldModule::KEY;

    /** @var array<int, FieldRole> */
    protected array $viewRoles = [FieldRole::Manager, FieldRole::Dispatcher, FieldRole::Viewer];

    /** @var array<int, FieldRole> */
    protected array $editRoles = [FieldRole::Manager];

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->allowed() && ! in_array(app(FieldAccess::class)->role($user), $this->viewRoles, true)) {
            return Response::deny(__('field.denied.no_screen'));
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

        if ($response->allowed() && ! in_array(app(FieldAccess::class)->role($user), $this->editRoles, true)) {
            return Response::deny(__('field.denied.manager'));
        }

        return $response;
    }
}
