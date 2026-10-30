<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Site;
use App\Support\Country;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Customers from a spreadsheet (Excel .xlsx or .csv), e.g. exported from the company's management system.
 *
 * - The first line has the column names, in any of the 3 languages (the model to download has them right);
 *   only "name" is required, the rest is optional and the order does not matter.
 * - A line whose "code in your system" (or document) already exists updates that customer instead of
 *   creating another; empty cells never erase what is there.
 * - Each line is checked with the same rules as the form (CustomerRules). A wrong line is skipped and reported
 *   with its number; the others are saved.
 * - Latitude and longitude in the spreadsheet are used as they are; without them, the address is searched on
 *   the map in the background (AddressLocator).
 */
class CustomerImporter
{
    /** Lines per file (bigger files: split them). */
    public const MAX_ROWS = 5000;

    public const COLUMNS = [
        'code', 'name', 'trade_name', 'kind', 'document', 'document_type', 'phone', 'phone_2', 'email', 'notes',
        'site_label', 'postal_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'reference',
        'contact_name', 'contact_phone', 'latitude', 'longitude',
    ];

    /** Other names people use for the columns (besides the names of the model in the 3 languages). */
    protected const ALIASES = [
        'document' => ['cpf', 'cnpj', 'cpf_cnpj', 'cpf_ou_cnpj', 'cnpj_cpf', 'rut', 'rfc', 'nit', 'cuit', 'dni'],
        'name' => ['razao_social', 'nome_completo', 'cliente', 'customer', 'razon_social'],
        'trade_name' => ['fantasia'],
        'code' => ['codigo', 'id', 'id_cliente', 'codigo_cliente'],
        'phone' => ['celular', 'telefone', 'whatsapp', 'fone', 'mobile', 'telefono'],
        'email' => ['e_mail', 'correo'],
        'street' => ['logradouro', 'endereco', 'rua', 'address', 'direccion', 'calle'],
        'number' => ['numero', 'num', 'no'],
        'district' => ['bairro', 'barrio', 'neighborhood'],
        'city' => ['cidade', 'municipio', 'ciudad'],
        'state' => ['uf', 'estado', 'provincia'],
        'postal_code' => ['cep', 'zip', 'zip_code', 'codigo_postal'],
        'latitude' => ['lat'],
        'longitude' => ['lng', 'lon', 'long'],
        'notes' => ['observacao', 'observacoes', 'obs'],
    ];

    public function __construct(protected CustomerRules $rules) {}

    /**
     * @return array{created: int, updated: int, errors: array<int, array{line: int, message: string}>}
     */
    public function import(string $path, string $extension): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $reader = strtolower($extension) === 'csv' ? new CsvReader($this->csvOptions($path)) : new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $this->cells($row);

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        if (! in_array('name', $columns, true)) {
                            $result['errors'][] = ['line' => 1, 'message' => __('field.import.no_name_column')];

                            return $result;
                        }

                        continue;
                    }

                    if (collect($cells)->filter(fn ($value): bool => filled($value))->isEmpty()) {
                        continue;
                    }

                    if ($line - 1 > self::MAX_ROWS) {
                        $result['errors'][] = ['line' => $line, 'message' => __('field.import.too_many', ['max' => self::MAX_ROWS])];

                        return $result;
                    }

                    $data = [];

                    foreach ($columns as $index => $key) {
                        if ($key !== null && array_key_exists($index, $cells)) {
                            $data[$key] = is_string($cells[$index]) ? trim($cells[$index]) : $cells[$index];
                        }
                    }

                    $outcome = $this->line($data);

                    if (is_string($outcome)) {
                        $result['errors'][] = ['line' => $line, 'message' => $outcome];
                    } else {
                        $result[$outcome ? 'created' : 'updated']++;
                    }
                }

                break; // Only the first sheet.
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /**
     * One line: true = created, false = updated, string = why it was skipped.
     *
     * @param  array<string, mixed>  $data
     */
    protected function line(array $data): bool|string
    {
        $data = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_scalar($value) ? (string) $value : null), $data);
        $existing = filled($data['code'] ?? null) ? Customer::query()->where('external_code', trim($data['code']))->first() : null;
        $existing ??= filled($data['document'] ?? null) ? $this->rules->duplicateDocument($data['document']) : null;

        $validator = Validator::make($data, [
            'name' => [$existing ? 'nullable' : 'required', 'max:255'],
            'trade_name' => ['nullable', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'document' => ['nullable', 'max:40', $this->rules->documentRule($existing)],
            'code' => ['nullable', 'max:60', $this->rules->externalCodeRule($existing)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'postal_code' => ['nullable', 'max:20'],
            'state' => ['nullable', 'max:60'],
        ], [], collect(array_keys($data))->mapWithKeys(fn (string $key): array => [$key => __('field.import.columns.'.$key)])->all());

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $customerData = $this->rules->prepare(array_filter([
            'external_code' => $data['code'] ?? null,
            'name' => $data['name'] ?? null,
            'trade_name' => $data['trade_name'] ?? null,
            'kind' => $this->kind($data['kind'] ?? null),
            'document' => $data['document'] ?? null,
            'document_type' => $data['document_type'] ?? null,
            'phone' => $data['phone'] ?? null,
            'phone_2' => $data['phone_2'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value): bool => filled($value)));

        $siteData = array_filter([
            'label' => $data['site_label'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'street' => $data['street'] ?? null,
            'number' => $data['number'] ?? null,
            'complement' => $data['complement'] ?? null,
            'district' => $data['district'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'reference' => $data['reference'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_phone' => isset($data['contact_phone']) ? Country::phone($data['contact_phone']) : null,
        ], fn ($value): bool => filled($value));
        $point = filled($data['latitude'] ?? null) ? ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6)] : null;

        return DB::transaction(function () use ($existing, $customerData, $siteData, $point): bool {
            $customer = $existing ?? new Customer;
            $customer->fill($customerData)->save();

            if ($siteData !== [] || $point !== null) {
                $site = $this->sameSite($customer, $siteData) ?? new Site(['is_main' => ! $customer->sites()->exists()]);
                $site->fill($siteData);
                $site->customer()->associate($customer);

                if ($point !== null) {
                    $site->forceFill($point + ['location_source' => Site::IMPORT]);
                }

                $site->save();
            }

            return $existing === null;
        });
    }

    /** The customer's site with the same postal code and number (or street and number), to update instead of adding. */
    protected function sameSite(Customer $customer, array $site): ?Site
    {
        if (! $customer->exists || blank($site['number'] ?? null)) {
            return $customer->exists && ! filled($site['street'] ?? null) && ! filled($site['postal_code'] ?? null) ? $customer->sites()->first() : null;
        }

        $digits = fn (?string $value): string => (string) preg_replace('/\D/', '', (string) $value);

        return $customer->sites()->get()->first(fn (Site $existing): bool => mb_strtolower(trim((string) $existing->number)) === mb_strtolower(trim((string) $site['number']))
            && ((filled($site['postal_code'] ?? null) && $digits($existing->postal_code) === $digits($site['postal_code']))
                || (filled($site['street'] ?? null) && Str::slug((string) $existing->street) === Str::slug((string) $site['street']))));
    }

    protected function kind(?string $typed): ?string
    {
        $value = Str::slug((string) $typed, '_');

        return match (true) {
            $value === '' => null,
            in_array($value, ['pessoa', 'person', 'persona', 'pf', 'fisica', 'pessoa_fisica', Customer::PERSON], true) => Customer::PERSON,
            default => Customer::COMPANY,
        };
    }

    /**
     * Which known column each spreadsheet column is (null = ignored).
     *
     * @param  array<int, mixed>  $headers
     * @return array<int, ?string>
     */
    protected function columns(array $headers): array
    {
        $known = [];

        foreach (self::COLUMNS as $key) {
            $known[$key] = $key;

            foreach (array_keys(config('kiban.locales')) as $locale) {
                $known[Str::slug(__('field.import.columns.'.$key, [], $locale), '_')] = $key;
            }
        }

        foreach (self::ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                $known[$alias] ??= $key;
            }
        }

        $taken = [];

        return array_map(function ($header) use ($known, &$taken): ?string {
            $key = $known[Str::slug((string) $header, '_')] ?? null;

            if ($key === null || isset($taken[$key])) {
                return null;
            }

            $taken[$key] = true;

            return $key;
        }, $headers);
    }

    /**
     * @return array<int, mixed>
     */
    protected function cells(Row $row): array
    {
        return $row->toArray();
    }

    /** Excel in Brazil saves CSV with ";"; elsewhere usually ",". Old Excel files are not UTF-8. */
    protected function csvOptions(string $path): CsvOptions
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        // A sample cut at the end of a line (a cut in the middle of a letter is not an encoding error).
        $sample = (string) file_get_contents($path, length: 65536);
        $sample = substr($sample, 0, strrpos($sample, "\n") ?: strlen($sample));

        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        return $options;
    }

    /** The model to download: the column names in the person's language and one example line. */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kiban').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn (string $key): string => __('field.import.columns.'.$key), self::COLUMNS)));
        $writer->addRow(Row::fromValues(array_map(fn (string $key): string => (string) __('field.import.example.'.$key), self::COLUMNS)));
        $writer->close();

        return $path;
    }
}
