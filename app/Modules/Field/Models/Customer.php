<?php

namespace App\Modules\Field\Models;

use App\Modules\ReportsFirstSteps;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer of the company (who receives the services): a company or a person, with one or more service
 * addresses (sites). The document is CPF/CNPJ in Brazil and any document elsewhere; the "code in your system"
 * links it to the company's own management system, so a new spreadsheet updates instead of duplicating.
 */
class Customer extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const COMPANY = 'company';

    public const PERSON = 'person';

    protected $table = 'fd_customers';

    protected $fillable = ['kind', 'name', 'trade_name', 'document_type', 'document', 'external_code', 'phone', 'phone_2', 'email', 'notes', 'is_active'];

    protected $attributes = ['kind' => self::COMPANY, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->orderByDesc('is_main')->orderBy('id');
    }

    /**
     * The main address (there is always exactly one when there are addresses: ensureMainSite()).
     *
     * @return HasOne<Site, $this>
     */
    public function mainSite(): HasOne
    {
        return $this->hasOne(Site::class)->where('is_main', true);
    }

    /**
     * @param  Builder<Customer>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Exactly one main address: the one marked (the first one when none or many are marked). */
    public function ensureMainSite(): void
    {
        $sites = $this->sites()->get();
        $main = $sites->firstWhere('is_main', true) ?? $sites->first();

        if ($main === null) {
            return;
        }

        Site::query()->where('customer_id', $this->getKey())->whereKeyNot($main->getKey())->where('is_main', true)->update(['is_main' => false]);

        if (! $main->is_main) {
            $main->forceFill(['is_main' => true])->save();
        }
    }

    /** The name people know: the trade name of a company, when there is one. */
    public function displayName(): string
    {
        return filled($this->trade_name) ? $this->trade_name.' ('.$this->name.')' : $this->name;
    }

    /** The document as people read it: CPF 000.000.000-00, CNPJ 00.000.000/0000-00 or "type number". */
    public function documentLabel(): ?string
    {
        if (blank($this->document)) {
            return null;
        }

        return match ($this->document_type) {
            'cpf' => 'CPF '.vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split(str_pad($this->document, 11, '0', STR_PAD_LEFT))),
            'cnpj' => 'CNPJ '.vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split(str_pad($this->document, 14, '0', STR_PAD_LEFT))),
            default => trim(($this->document_type ? mb_strtoupper($this->document_type).' ' : '').$this->document),
        };
    }

    /**
     * Brazilian document from what was typed: CPF (11 digits) or CNPJ (14), checked. Null when it is not one.
     *
     * @return array{type: string, number: string}|null
     */
    public static function brazilianDocument(?string $typed): ?array
    {
        $digits = (string) preg_replace('/\D/', '', (string) $typed);

        return match (true) {
            strlen($digits) === 11 && Cpf::isValid($digits) => ['type' => 'cpf', 'number' => $digits],
            strlen($digits) === 14 && Cnpj::isValid($digits) => ['type' => 'cnpj', 'number' => $digits],
            default => null,
        };
    }
}
