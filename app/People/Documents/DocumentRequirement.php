<?php

namespace App\People\Documents;

use App\Models\Team;
use App\Modules\ModuleRegistry;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Who needs this course": everyone in the company, one team, or the people with a role in a system
 * (e.g. NR-10 for the Campo technicians). Someone in the scope without the course is "missing" (Falta fazer).
 * Rules in TrainingService.
 */
class DocumentRequirement extends Model
{
    use BelongsToTenant;

    public const ALL = 'all';

    public const TEAM = 'team';

    public const ROLE = 'role';

    protected $table = 'person_document_requirements';

    protected $fillable = ['document_type_id', 'scope', 'team_id', 'module', 'role'];

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** "Todos", "Equipe Instalação" or "Campo: Técnico". */
    public function targetLabel(): string
    {
        return match ($this->scope) {
            self::TEAM => __('documents.trainings.requirements.team_label', ['team' => $this->team?->name ?? '—']),
            self::ROLE => static::roleLabel((string) $this->module, (string) $this->role),
            default => __('documents.trainings.requirements.everyone'),
        };
    }

    /** "Campo: Técnico". */
    public static function roleLabel(string $module, string $role): string
    {
        $system = app(ModuleRegistry::class)->all()[$module] ?? null;

        if ($system === null) {
            return $module.': '.$role;
        }

        return __('documents.trainings.requirements.role_label', ['system' => $system->name(), 'role' => $system->roles()[$role] ?? $role]);
    }
}
