<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The company's identity on what goes to its own customers (reports, quotes...): logo, color, legal name,
 * document, contacts, address and a footer. Without it, the company's name from the license and the Kiban blue.
 */
class CompanyProfile extends Model
{
    use BelongsToTenant;

    public const DEFAULT_COLOR = '#044299';

    protected $fillable = ['logo_path', 'color', 'legal_name', 'document', 'phone', 'email', 'website', 'address', 'report_footer'];

    public static function current(): self
    {
        // Not saved yet: every field empty (so nothing is "missing").
        return static::query()->first() ?? new static(array_fill_keys((new static)->getFillable(), null));
    }

    public function name(): string
    {
        return filled($this->legal_name) ? $this->legal_name : app(TenantContext::class)->get()->name;
    }

    public function color(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->color) ? $this->color : self::DEFAULT_COLOR;
    }

    /** The logo as a data URL (it goes inside reports and PDFs, which have no access to private files). */
    public function logoDataUrl(): ?string
    {
        if (blank($this->logo_path) || ! Storage::disk('local')->exists($this->logo_path)) {
            return null;
        }

        $mime = Storage::disk('local')->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($this->logo_path));
    }
}
