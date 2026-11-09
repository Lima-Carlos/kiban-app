<?php

namespace Tests\Feature;

use App\Exports\CompanyExport;
use App\Filament\Pages\CompanyProfilePage;
use App\Jobs\ExportCompanyData;
use App\Models\Tenant;
use App\Models\User;
use App\Notices\Notifier;
use App\People\Person;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * "Download everything": the company's records (one spreadsheet per kind) and files in one .zip, without secrets,
 * only of that company, for the owner and the administrators, for 7 days.
 */
class CompanyExportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
    }

    public function test_the_file_has_the_company_data_only(): void
    {
        Storage::fake('local');
        Person::create(['name' => 'Ana Souza', 'email' => 'ana@exemplo.com']);
        Storage::disk('local')->put('tenants/'.$this->company->id.'/fotos/a.jpg', 'foto');

        $other = $this->tenant('outra', ['ponto' => []]);
        app(TenantContext::class)->run($other, fn () => Person::create(['name' => 'Bruno de Outra Empresa']));
        app(TenantContext::class)->set($this->company);

        (new ExportCompanyData($this->company->id, $this->owner->id))->handle(app(CompanyExport::class), app(TenantContext::class), app(Notifier::class));

        $files = Storage::disk('local')->files(CompanyExport::folder($this->company));
        $this->assertCount(1, $files);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($files[0]));
        $people = (string) $zip->getFromName('planilhas/people.csv');
        $this->assertStringContainsString('Ana Souza', $people);
        $this->assertStringNotContainsString('Bruno de Outra Empresa', $people);
        $this->assertSame('foto', $zip->getFromName('arquivos/fotos/a.jpg'));
        $this->assertStringContainsString('Instaladora', (string) $zip->getFromName('LEIA-ME.txt'));
        $zip->close();

        // The bell has the link; it opens for the owner, not for a member.
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->get(route('company.export', ['file' => basename($files[0])]))->assertOk();
        $this->signIn($this->company, 'member');
        $this->get(route('company.export', ['file' => basename($files[0])]))->assertForbidden();

        // A week later it is gone.
        $this->travel(8)->days();
        touch(Storage::disk('local')->path($files[0]), now()->subDays(8)->getTimestamp());
        $this->assertSame(1, app(CompanyExport::class)->cleanup());
    }

    public function test_the_button_queues_it(): void
    {
        Queue::fake();
        Livewire::test(CompanyProfilePage::class)->callAction('exportAll')->assertNotified(__('app.export.started'));
        Queue::assertPushed(ExportCompanyData::class, fn (ExportCompanyData $job): bool => $job->tenantId === $this->company->id && $job->userId === $this->owner->id);
    }
}
