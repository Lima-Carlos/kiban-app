<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Models\Customer;
use App\Support\Country;
use Closure;

/**
 * The rules of a customer, the same for the form, the spreadsheet import and (later) the store/API:
 * - in Brazil the document is a CPF or a CNPJ, checked; elsewhere any document, with its type;
 * - the document and the "code in your system" are unique in the company;
 * - phones keep the country code (like the people of the company);
 * - the kind (company/person) follows the Brazilian document when it is not given.
 */
class CustomerRules
{
    /** Validation of the document typed on the form or in a spreadsheet line. */
    public function documentRule(?Customer $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (blank($value)) {
                return;
            }

            if (Country::isBrazil() && Customer::brazilianDocument((string) $value) === null) {
                $fail(__('field.errors.document'));

                return;
            }

            if ($this->duplicateDocument((string) $value, $ignore) !== null) {
                $fail(__('field.errors.document_taken'));
            }
        };
    }

    public function externalCodeRule(?Customer $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (filled($value) && Customer::query()->where('external_code', trim((string) $value))->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))->exists()) {
                $fail(__('field.errors.code_taken'));
            }
        };
    }

    /** Another customer of the company with this document. */
    public function duplicateDocument(string $typed, ?Customer $ignore = null): ?Customer
    {
        $number = $this->documentNumber($typed);

        if (blank($number)) {
            return null;
        }

        return Customer::query()->where('document', $number)->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))->first();
    }

    /** The document as it is kept (digits of a CPF/CNPJ in Brazil, the text elsewhere). */
    public function documentNumber(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        return Country::isBrazil() ? (Customer::brazilianDocument($typed)['number'] ?? null) : $typed;
    }

    /**
     * Turns what was typed into what is kept (document split in type and number, phones with the country code).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data): array
    {
        if (array_key_exists('document', $data)) {
            $typed = trim((string) $data['document']);

            if ($typed === '') {
                $data['document'] = null;
                $data['document_type'] = null;
            } elseif (Country::isBrazil() && ($document = Customer::brazilianDocument($typed)) !== null) {
                $data['document'] = $document['number'];
                $data['document_type'] = $document['type'];
                $data['kind'] = filled($data['kind'] ?? null) ? $data['kind'] : ($document['type'] === 'cpf' ? Customer::PERSON : Customer::COMPANY);
            } else {
                $data['document'] = $typed;
                $data['document_type'] = filled($data['document_type'] ?? null) ? mb_strtolower(trim((string) $data['document_type'])) : null;
            }
        }

        foreach (['phone', 'phone_2'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Country::phone($data[$field]);
            }
        }

        foreach (['external_code', 'email', 'name', 'trade_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim((string) $data[$field]) : null;
            }
        }

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        if (array_key_exists('kind', $data) && ! in_array($data['kind'], [Customer::COMPANY, Customer::PERSON], true)) {
            $data['kind'] = Customer::COMPANY;
        }

        return $data;
    }

    /** The document as it is shown on the form (with dots and dashes in Brazil). */
    public function typedDocument(Customer $customer): ?string
    {
        if (blank($customer->document)) {
            return null;
        }

        return in_array($customer->document_type, ['cpf', 'cnpj'], true)
            ? (string) preg_replace('/^(CPF|CNPJ) /', '', (string) $customer->documentLabel())
            : $customer->document;
    }
}
