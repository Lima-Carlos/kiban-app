<?php

namespace Tests\Feature;

use App\Modules\Stock\Filament\Balances\Pages\ManageBalances;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\InvoiceReader;
use App\Modules\Stock\Services\StockException;
use App\Modules\Stock\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Estoque: the entry of a purchase from the XML of the NF-e (the supplier's electronic invoice), with each line
 * matched to an item of the stock or created, and the same invoice never entered twice.
 */
class StockInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public const KEY = '35261112345678000199550010000012341000012345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->signIn($this->tenant('provedor', ['estoque' => []]));
    }

    public static function xml(): string
    {
        $key = self::KEY;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe><infNFe Id="NFe{$key}" versao="4.00">
    <ide><nNF>1234</nNF></ide>
    <emit><xNome>DISTRIBUIDORA FIBRA LTDA</xNome><xFant>Fibra Distribuidora</xFant></emit>
    <det nItem="1"><prod><cProd>CB-DROP</cProd><cEAN>SEM GTIN</cEAN><xProd>CABO DROP 1F</xProd><uCom>MT</uCom><qCom>1000.0000</qCom><vUnCom>0.85</vUnCom></prod></det>
    <det nItem="2"><prod><cProd>CON-SC</cProd><cEAN>7891234567895</cEAN><xProd>CONECTOR SC/APC</xProd><uCom>PC</uCom><qCom>200.0000</qCom><vUnCom>1.20</vUnCom></prod></det>
    <det nItem="3"><prod><cProd>FITA</cProd><cEAN></cEAN><xProd>FITA ISOLANTE</xProd><uCom>UN</uCom><qCom>10.0000</qCom><vUnCom>5.00</vUnCom></prod></det>
  </infNFe></NFe>
</nfeProc>
XML;
    }

    public function test_reading_matching_and_entering_an_invoice(): void
    {
        $warehouse = Location::create(['name' => 'Almoxarifado']);
        $cable = Item::create(['name' => 'Cabo drop', 'sku' => 'CB-DROP', 'unit' => 'm']);
        $tape = Item::create(['name' => 'Fita isolante', 'unit' => 'un']);

        $invoice = app(InvoiceReader::class)->read(self::xml());
        $this->assertSame(['1234', 'Fibra Distribuidora', self::KEY, false], [$invoice['number'], $invoice['supplier'], $invoice['key'], $invoice['already']]);
        $this->assertSame(['m', 'un', 'un'], array_column($invoice['lines'], 'unit'));
        // By code, none, by name.
        $this->assertSame([$cable->getKey(), null, $tape->getKey()], array_column($invoice['lines'], 'item_id'));

        $lines = $invoice['lines'];
        $lines[1]['item_id'] = 'new';
        $lines[2]['item_id'] = 'skip';
        $this->assertSame(2, app(StockService::class)->receiveInvoice($invoice, $lines, $warehouse));

        $connector = Item::query()->where('sku', 'CON-SC')->sole();
        $this->assertSame(['CONECTOR SC/APC', 'un'], [$connector->name, $connector->unit]);
        $this->assertSame([1000.0, 200.0, 0.0], [app(StockService::class)->balance($cable, $warehouse), app(StockService::class)->balance($connector, $warehouse), app(StockService::class)->balance($tape, $warehouse)]);

        // The same invoice again is refused, and the reader says so before.
        $this->assertTrue(app(InvoiceReader::class)->read(self::xml())['already']);
        $this->assertThrows(fn () => app(StockService::class)->receiveInvoice($invoice, $lines, $warehouse), StockException::class);
    }

    public function test_files_that_are_not_an_nfe_are_refused(): void
    {
        $this->assertThrows(fn () => app(InvoiceReader::class)->read('%PDF-1.4 not xml'), StockException::class);
        $this->assertThrows(fn () => app(InvoiceReader::class)->read('<?xml version="1.0"?><other/>'), StockException::class);

        // An external entity is never fetched.
        $evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe1"><ide><nNF>&e;</nNF></ide><det><prod><cProd>A</cProd><xProd>A</xProd><uCom>UN</uCom><qCom>1</qCom></prod></det></infNFe></NFe></nfeProc>';
        $this->assertStringNotContainsString('root:', app(InvoiceReader::class)->read($evil)['number']);
    }

    public function test_the_two_steps_on_the_screen(): void
    {
        $warehouse = Location::create(['name' => 'Almoxarifado']);
        $file = UploadedFile::fake()->createWithContent('nota.xml', self::xml());

        Livewire::test(ManageBalances::class)
            ->callAction('invoice', ['xml' => $file, 'location_id' => $warehouse->getKey()])
            ->assertActionMounted('invoiceLines')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(3, Item::query()->count());
        $this->assertSame(1000.0, app(StockService::class)->balance(Item::query()->where('sku', 'CB-DROP')->sole(), $warehouse));
    }

    public function test_texts_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['stock.invoice.action', 'stock.invoice.check_help', 'stock.invoice.errors.already', 'stock.invoice.new_item'] as $key) {
                $this->assertNotSame($key, __($key), $locale.' '.$key);
            }
        }
    }
}
