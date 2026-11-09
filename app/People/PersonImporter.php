<?php

namespace App\People;

use App\Models\Team;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\TimeClockModule;
use App\Rules\Cpf;
use App\Support\Country;
use App\Support\Spreadsheet\SpreadsheetImporter;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Employees from a spreadsheet (e.g. the list the accountant or the old system has), so the company starts without
 * typing everyone. People come in without a login ("Dar acesso" later, one by one). A team that does not exist yet
 * is created. With the Ponto: "Bate ponto" = sim, with the admission date, the workplace and the schedule by name
 * (or "externo" for field work), the registration (Matrícula) and the PIS. Someone already registered (same CPF or
 * e-mail) is not repeated: the line is skipped and reported.
 */
class PersonImporter extends SpreadsheetImporter
{
    public function __construct(protected PeopleService $people, protected TenantContext $context) {}

    protected function columnKeys(): array
    {
        return ['name', 'phone', 'email', 'document', 'team', 'clocks_in', 'registration', 'pis', 'job_title', 'admitted_on', 'workplace', 'schedule'];
    }

    protected function requiredColumn(): string
    {
        return 'name';
    }

    protected function langPrefix(): string
    {
        return 'app.people.import';
    }

    protected function aliases(): array
    {
        return [
            'name' => ['nome', 'nome_completo', 'funcionario', 'colaborador', 'empleado', 'employee', 'nombre'],
            'phone' => ['celular', 'telefone', 'whatsapp', 'fone', 'mobile', 'telefono'],
            'email' => ['e_mail', 'correo'],
            'document' => ['cpf', 'documento', 'rg', 'dni', 'curp', 'rut'],
            'team' => ['equipe', 'setor', 'departamento', 'equipo', 'area'],
            'registration' => ['matricula', 'codigo', 'chapa', 'cadastro'],
            'pis' => ['pis_pasep', 'nis', 'nit'],
            'job_title' => ['cargo', 'funcao', 'puesto'],
            'admitted_on' => ['admissao', 'data_admissao', 'data_de_admissao', 'admision'],
            'workplace' => ['local', 'local_de_trabalho', 'filial', 'lugar_de_trabajo'],
            'schedule' => ['horario', 'escala', 'jornada'],
            'clocks_in' => ['bate_ponto', 'ponto', 'marca_asistencia'],
        ];
    }

    protected function line(array $data): bool|string
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'max:30'],
            'document' => ['nullable', 'max:40'],
            'team' => ['nullable', 'max:100'],
        ], [], collect(array_keys($data))->mapWithKeys(fn (string $key): array => [$key => __('app.people.import.columns.'.$key)])->all());

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $document = filled($data['document'] ?? null) ? (string) $data['document'] : null;

        if ($document !== null && Country::isBrazil() && ! Cpf::isValid($document)) {
            return __('app.people.import.invalid_cpf');
        }

        $already = Person::query()
            ->where(fn ($query) => $query
                ->when($document !== null, fn ($q) => $q->orWhere('document', Country::isBrazil() ? Cpf::digits($document) : $document))
                ->when(filled($data['email'] ?? null), fn ($q) => $q->orWhere('email', mb_strtolower((string) $data['email']))))
            ->when($document === null && blank($data['email'] ?? null), fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if ($already !== null) {
            return __('app.people.import.already', ['name' => $already->name]);
        }

        $modules = [];

        if ($this->yes($data['clocks_in'] ?? null)) {
            if (! $this->context->get()->license()->hasModule(TimeClockModule::KEY)) {
                return __('app.people.import.no_timeclock');
            }

            $ponto = $this->timeClock($data);

            if (is_string($ponto)) {
                return $ponto;
            }

            $modules[TimeClockModule::KEY] = $ponto;
        }

        try {
            $this->people->add($this->context->get(), [
                'name' => (string) $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => filled($data['email'] ?? null) ? mb_strtolower((string) $data['email']) : null,
                'document' => $document,
                'team_id' => $this->team($data['team'] ?? null),
            ], $modules, auth()->user(), false);
        } catch (PersonRuleException $exception) {
            return $exception->getMessage();
        }

        return true;
    }

    /**
     * The Ponto section of a line, or why it cannot be used.
     *
     * @param  array<string, ?string>  $data
     * @return array<string, mixed>|string
     */
    protected function timeClock(array $data): array|string
    {
        $admitted = $this->date($data['admitted_on'] ?? null);

        if ($admitted === null) {
            return __('app.people.import.admitted_on');
        }

        $external = in_array(Str::slug((string) ($data['workplace'] ?? ''), '_'), ['externo', 'external', 'externa', 'campo', 'rua'], true);
        $workplace = $external ? null : $this->byName(Workplace::class, $data['workplace'] ?? null);

        if (! $external && $workplace === null) {
            return __('app.people.import.workplace', ['name' => (string) ($data['workplace'] ?? '')]);
        }

        $schedule = $this->byName(Schedule::class, $data['schedule'] ?? null);

        if ($schedule === null) {
            return __('app.people.import.schedule', ['name' => (string) ($data['schedule'] ?? '')]);
        }

        return [
            'clocks_in' => true,
            'admitted_on' => $admitted,
            'workplace_id' => $workplace,
            'is_external' => $external,
            'schedule_id' => $schedule,
            'registration' => $data['registration'] ?? null,
            'pis' => $data['pis'] ?? null,
            'job_title' => $data['job_title'] ?? null,
        ];
    }

    /** @param  class-string<Model>  $model */
    protected function byName(string $model, ?string $name): ?int
    {
        if (blank($name)) {
            return $model::query()->count() === 1 ? (int) $model::query()->value('id') : null;
        }

        return $model::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->value('id');
    }

    protected function team(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        $team = Team::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first()
            ?? Team::create(['name' => trim($name), 'is_active' => true]);

        return $team->getKey();
    }
}
