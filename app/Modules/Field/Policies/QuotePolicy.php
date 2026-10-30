<?php

namespace App\Modules\Field\Policies;

use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Models\Quote;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Quotes: the manager and the service desk write and send them; the viewer only looks. A quote the customer
 * already answered does not change and is never deleted (it is the proof of what was agreed).
 */
class QuotePolicy extends FieldPolicy
{
    protected array $editRoles = [FieldRole::Manager, FieldRole::Dispatcher];

    public function delete(User $user, Model $record): Response
    {
        /** @var Quote $record */
        if (! $record->isEditable()) {
            return Response::deny(__('field.errors.quote_answered'));
        }

        return parent::delete($user, $record);
    }
}
