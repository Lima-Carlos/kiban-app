<?php

namespace App\Modules\Fleet\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One item of the company's inspection checklist. Ready items have a "key" and a translated name
 * (the manager may rename them); the company's own items have only a "label".
 */
class ChecklistItem extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_checklist_items';

    protected $fillable = ['key', 'label', 'is_critical', 'is_active', 'sort'];

    protected $attributes = ['is_critical' => false, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** The name the person sees (in their language for ready items, unless the manager renamed it). */
    public function name(): string
    {
        return filled($this->label) ? (string) $this->label : __('fleet.checklist.'.$this->key);
    }

    /**
     * @param  Builder<ChecklistItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }
}
