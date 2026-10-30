<?php

namespace App\People\Documents;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document the company follows for its people (driver's license, medical exam, NR-10...).
 * Ready types come from DocumentTemplates by the company's country (name translated by "key"); the company
 * renames, changes, turns off or adds its own. A type is never deleted (documents point to it).
 */
class DocumentType extends Model
{
    use BelongsToTenant;

    protected $table = 'person_document_types';

    protected $fillable = ['key', 'name', 'validity_months', 'alert_days', 'is_health', 'module', 'is_active', 'sort'];

    protected $attributes = ['alert_days' => 30, 'is_health' => false, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'validity_months' => 'integer',
            'alert_days' => 'integer',
            'is_health' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<PersonDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PersonDocument::class, 'document_type_id');
    }

    /** Name in the language of who reads it (ready types) or as the company typed it. */
    public function label(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return $this->key !== null ? __('documents.templates.'.$this->key) : '—';
    }

    /**
     * @param  Builder<DocumentType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<DocumentType>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
