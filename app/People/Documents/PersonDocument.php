<?php

namespace App\People\Documents;

use App\Models\User;
use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A document of a person with its validity (e.g. Ana's NR-10, valid until 10/03/2027) and the file (private).
 * Renewing = adding the new one: the old one gets "replaced_at" and stays as history.
 * Changes go through PersonDocumentService. The file is removed together with the record.
 */
class PersonDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'person_documents';

    protected $fillable = ['person_id', 'document_type_id', 'number', 'issued_on', 'expires_on', 'file_path', 'notes'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'replaced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The attachment never outlives its record (also when the person's data is deleted).
        static::deleted(function (PersonDocument $document): void {
            if (filled($document->file_path)) {
                Storage::disk('local')->delete($document->file_path);
            }
        });
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<PersonDocument>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('replaced_at'));
    }

    public function isCurrent(): bool
    {
        return $this->replaced_at === null;
    }

    /** Today in the company's time zone (a document expires at the end of its day there). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(FilamentTimezone::get())->startOfDay();
    }

    /** Days until it expires (0 = today, negative = expired), null when it does not expire. */
    public function daysLeft(?CarbonInterface $today = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        $today = CarbonImmutable::parse(($today ?? static::today())->toDateString());

        return (int) $today->diffInDays(CarbonImmutable::parse($this->expires_on->toDateString()), false);
    }

    public function status(?CarbonInterface $today = null): DocumentStatus
    {
        $days = $this->daysLeft($today);

        return match (true) {
            $days === null => DocumentStatus::NoExpiry,
            $days < 0 => DocumentStatus::Overdue,
            $days <= ($this->type?->alert_days ?? 30) => DocumentStatus::Soon,
            default => DocumentStatus::Ok,
        };
    }

    /** "Em dia", "Vence em 12 dias", "Vence hoje", "Vencido" or "Sem vencimento". */
    public function statusText(?CarbonInterface $today = null): string
    {
        $status = $this->status($today);

        if ($status !== DocumentStatus::Soon) {
            return $status->getLabel();
        }

        $days = (int) $this->daysLeft($today);

        return $days === 0 ? __('documents.status.today') : trans_choice('documents.status.in_days', $days, ['days' => $days]);
    }
}
