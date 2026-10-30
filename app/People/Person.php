<?php

namespace App\People;

use App\Models\Team;
use App\Models\User;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\Rules\Cpf;
use App\Support\Country;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone of the company (the "People" screen): the central record every module uses.
 * The login is optional: a person may only clock in on the company tablet. When they get a login,
 * the Core user is linked here and name, mobile and e-mail follow the Core.
 * The document is the CPF in Brazil and a generic one (type + number) in other countries.
 */
class Person extends Model
{
    use BelongsToTenant;

    public const CPF = 'cpf';

    protected $fillable = ['name', 'phone', 'email', 'document_type', 'document', 'team_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // When the system deletes a person's data, their documents and files go with it.
        static::deleting(fn (Person $person) => app(PersonDocumentService::class)->purgePerson($person));
    }

    /**
     * Documents with a validity (driver's license, medical exam, trainings), current ones and history.
     *
     * @return HasMany<PersonDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PersonDocument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function hasLogin(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * @param  Builder<Person>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "CPF 123.456.789-09" or "DNI 12345678", for screens and documents. */
    public function documentLabel(): ?string
    {
        if (blank($this->document)) {
            return null;
        }

        return $this->document_type === self::CPF
            ? 'CPF '.Cpf::format($this->document)
            : trim($this->document_type.' '.$this->document);
    }

    /**
     * Keeps the document in the company country's way: CPF (digits) in Brazil, free text elsewhere.
     */
    public function setDocument(?string $number, ?string $type = null): void
    {
        $number = filled($number) ? trim((string) $number) : null;

        if (Country::isBrazil()) {
            $this->document_type = $number !== null ? self::CPF : null;
            $this->document = Cpf::digits($number);

            return;
        }

        $this->document_type = $number !== null ? (filled($type) ? trim((string) $type) : null) : null;
        $this->document = $number;
    }

    /**
     * The record of this login in the current company (created the first time; name, mobile and e-mail follow the Core).
     */
    public static function forUser(User $user): self
    {
        $person = static::query()->where('user_id', $user->getKey())->first() ?? new static;
        $person->fill(['name' => $user->name, 'phone' => $user->phone, 'email' => $user->email, 'is_active' => true]);
        $person->forceFill(['user_id' => $user->getKey()]);

        if ($person->isDirty() || ! $person->exists) {
            $person->save();
        }

        return $person;
    }
}
