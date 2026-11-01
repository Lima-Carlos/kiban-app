<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\Models\ClientPackage;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Packages for sale: the manager keeps them (a package already sold is turned off, never deleted).
 */
class PackagePolicy extends AgendaPolicy
{
    protected array $requiresSteps = ['services'];

    public function delete(User $user, Model $record): Response
    {
        if (ClientPackage::query()->where('package_id', $record->getKey())->exists()) {
            return Response::deny(__('agenda.denied.package_sold'));
        }

        return parent::delete($user, $record);
    }
}
