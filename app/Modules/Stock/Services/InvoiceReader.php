<?php

namespace App\Modules\Stock\Services;

use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Movement;

/**
 * Reads the XML of a Brazilian electronic invoice (NF-e, model 55) that the supplier sends with the purchase: the
 * number, the supplier, the access key and each product (code, barcode, description, unit, quantity). Each line
 * gets a suggested item of the stock (same code or barcode, or the same name). Never fetches anything from the
 * internet (no external entities).
 */
class InvoiceReader
{
    /**
     * @return array{number: string, supplier: string, key: string, already: bool, lines: array<int, array{code: string, ean: ?string, description: string, unit: string, quantity: float, item_id: int|string|null}>}
     */
    public function read(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new StockException(__('stock.invoice.errors.not_xml'));
        }

        $document->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $info = $document->xpath('//n:infNFe')[0] ?? null;

        if ($info === null) {
            throw new StockException(__('stock.invoice.errors.not_nfe'));
        }

        $info->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $text = fn (string $path): string => trim((string) ($info->xpath($path)[0] ?? ''));
        $key = preg_replace('/\D/', '', (string) $info['Id']);
        $lines = [];

        foreach ($info->xpath('n:det/n:prod') as $product) {
            $product->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $value = fn (string $tag): string => trim((string) ($product->xpath('n:'.$tag)[0] ?? ''));
            $ean = $value('cEAN');
            $line = [
                'code' => mb_substr($value('cProd'), 0, 60),
                'ean' => preg_match('/^\d{8,14}$/', $ean) ? $ean : null,
                'description' => mb_substr($value('xProd'), 0, 120),
                'unit' => $this->unit($value('uCom')),
                'quantity' => round((float) $value('qCom'), 3),
            ];
            $line['item_id'] = $this->match($line)?->getKey();
            $lines[] = $line;
        }

        if ($lines === []) {
            throw new StockException(__('stock.invoice.errors.no_lines'));
        }

        return [
            'number' => $text('n:ide/n:nNF'),
            'supplier' => mb_substr($text('n:emit/n:xFant') ?: $text('n:emit/n:xNome'), 0, 80),
            'key' => (string) $key,
            'already' => $key !== '' && Movement::query()->where('note', 'like', '%'.$key.'%')->exists(),
            'lines' => $lines,
        ];
    }

    /** Same code or barcode first, then the same name; serialized items are entered by serial number instead. */
    public function match(array $line): ?Item
    {
        $items = Item::query()->active()->where('serialized', false);

        return (clone $items)->whereIn('sku', array_filter([$line['code'], $line['ean']]))->first()
            ?? (clone $items)->whereRaw('lower(name) = ?', [mb_strtolower($line['description'])])->first();
    }

    /** "UN", "PC", "PÇ" → "un"; "M", "MT" → "m"; "KG" → "kg"; "CX" → "cx"... */
    protected function unit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));

        return match ($unit) {
            'un', 'und', 'unid', 'pc', 'pç', 'pca', 'pç.', 'peca', 'peça' => 'un',
            'm', 'mt', 'mts', 'metro' => 'm',
            'kg', 'kgs' => 'kg',
            'l', 'lt', 'lts' => 'l',
            '' => 'un',
            default => mb_substr($unit, 0, 10),
        };
    }
}
