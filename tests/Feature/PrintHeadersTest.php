<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Livewire\Reports\{RunAdHocReport, RunSavedPreset};
use App\Models\{Customer, Organization, Rental, ReportPreset, User, Vehicle};
use App\Services\Contracts\GenerateRentalContract;
use App\Services\Reports\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, Mail, Storage, View};
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrintHeadersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $renter;
    private Rental $rental;
    private array $contractData = [];

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) getenv('R4_QA_DATABASE');
        if (config('database.default') !== 'mysql' || $database === '') {
            $this->markTestSkipped('Usare php tests/mysql-payments.php per il database MySQL isolato.');
        }
        if (!preg_match('/^adm_era_qa_payments_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $database)
            || config('database.connections.mysql.database') !== $database
            || !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')) {
            throw new \RuntimeException('Database non consentito per il collaudo.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(10, 0));

        $owner = Organization::factory()->admin()->create(['name' => 'AMD Mobility']);
        $this->renter = Organization::factory()->renter()->create([
            'name' => 'Noleggiatore di collaudo & Servizi per la mobilità a lungo e breve termine',
            'rental_license' => false, 'rental_license_expires_at' => null,
        ]);
        $customer = Customer::factory()->create(['organization_id' => $this->renter->id,
            'name' => 'Cliente di collaudo', 'email' => 'customer@example.test']);
        $vehicle = Vehicle::factory()->create(['admin_organization_id' => $owner->id,
            'default_pickup_location_id' => null, 'make' => 'Auto di collaudo', 'model' => 'Stampa', 'plate' => 'QA002ZZ']);
        $this->rental = Rental::create(['organization_id' => $this->renter->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'number_id' => 1, 'amount' => 520, 'status' => 'closed',
            'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => '2026-09-04 10:00:00',
            'closed_at' => '2026-09-04 10:00:00', 'admin_fee_amount' => 78, 'admin_fee_percent' => 15]);
        $this->rental->charges()->create(['kind' => 'base', 'amount' => 520, 'is_commissionable' => true,
            'payment_recorded' => true, 'payment_recorded_at' => '2026-09-04 10:00:00', 'payment_method' => 'cash']);
        $this->rental->contractSnapshot()->create(['pricing_snapshot' => ['days' => 3,
            'currency' => 'EUR', 'tariff_total_cents' => 52000, 'deposit_cents' => 30000]]);

        $this->actingAs(User::factory()->create(['organization_id' => $owner->id]));
        View::composer('contracts.rental', function ($view): void {
            $this->contractData = $view->getData();
        });
    }

    public static function licenses(): array
    {
        return ['senza licenza' => [false], 'con licenza' => [true]];
    }

    #[DataProvider('licenses')]
    public function test_contract_header_identifies_the_renter_and_preserves_the_legal_lessor(bool $licensed): void
    {
        if ($licensed) $this->renter->update(['rental_license' => true]);
        $media = app(GenerateRentalContract::class)->handle($this->rental, forceUnsigned: true);
        $this->assertSame($this->renter->name, $this->contractData['renter_name']);
        $this->assertSame($licensed ? $this->renter->name : 'AMD Mobility', $this->contractData['org']['name']);
        $this->assertSame(!$licensed, $this->contractData['show_dual_lessor_box']);
        $this->assertSame('contract', $media->collection_name);
        $this->assertFalse($media->getCustomProperty('generated_with_signatures'));
        $html = view('contracts.rental', $this->contractData)->render();
        $label = $licensed ? 'Noleggiante' : 'Noleggiatore operativo';
        $this->assertStringContainsString($label.': <strong>'.e($this->renter->name).'</strong>', $html);
        $this->assertSame(52000, $this->rental->contractSnapshot->pricing_snapshot['tariff_total_cents']);
        $this->inspectPdf(file_get_contents($media->getPath()), 'contratto-'.($licensed ? 'licenza' : 'point').'.pdf');
    }

    #[DataProvider('licenses')]
    public function test_blank_contract_header_uses_the_current_operator_organization(bool $licensed): void
    {
        if ($licensed) $this->renter->update(['rental_license' => true]);
        $this->actingAs(User::factory()->create(['organization_id' => $this->renter->id]));
        $response = app(DashboardController::class)->printBlankContract(Request::create('/print/contracts/blank'));
        $this->assertSame($this->renter->name, $this->contractData['renter_name']);
        $this->assertSame($licensed ? $this->renter->name : 'AMD Mobility', $this->contractData['org']['name']);
        $this->assertSame('', $this->contractData['rental']['number_label']);
        $this->assertSame('', $this->contractData['customer']['name']);
        $this->inspectPdf($response->getContent(), 'vuoto-'.($licensed ? 'licenza' : 'point').'.pdf');
    }

    public static function reportTypes(): array
    {
        return [
            ['commissions_by_closure', 'sum_admin_fee_amount'],
            ['cash_by_payment_date', 'sum_paid_total'],
            ['cash_by_closure_month', 'sum_paid_total'],
        ];
    }

    #[DataProvider('reportTypes')]
    public function test_report_names_follow_the_result_scope_for_every_report_type(string $type, string $metric): void
    {
        $second = Organization::factory()->renter()->create(['name' => 'Secondo noleggiatore di collaudo']);
        $otherRental = $this->rental->replicate(['customer_id']);
        $otherRental->organization_id = $second->id;
        $otherRental->save();
        $otherRental->charges()->create(['kind' => 'base', 'amount' => 100, 'payment_recorded' => true,
            'payment_recorded_at' => '2026-09-05 10:00:00', 'payment_method' => 'cash']);
        $preset = $this->preset($type, $metric);
        $runner = app(ReportRunner::class);
        $this->assertSame([$this->renter->name, $second->name], $runner->organizationNamesFor($preset));

        $preset->filters = $preset->filters + ['organization_id' => $this->renter->id];
        $this->assertSame([$this->renter->name], $runner->organizationNamesFor($preset));

        $preset->filters = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];
        $this->assertSame([], $runner->organizationNamesFor($preset));

        $preset = $this->preset($type, $metric);
        if ($type === 'commissions_by_closure') {
            $otherRental->delete();
        } else {
            $otherRental->charges()->update(['payment_recorded' => false]);
        }
        $this->assertSame([$this->renter->name], $runner->organizationNamesFor($preset));
    }

    public function test_ad_hoc_header_stays_with_the_executed_results_when_filters_change(): void
    {
        $component = Livewire::test(RunAdHocReport::class)
            ->set('report_type', 'commissions_by_closure')
            ->set('metrics', ['sum_admin_fee_amount'])->set('dimensions', ['rental'])
            ->set('filters.organization_id', $this->renter->id)
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30')
            ->call('runReport')->assertHasNoErrors()->assertSet('runError', null)
            ->assertSet('printContext.organization_names', [$this->renter->name])
            ->assertSee('Noleggiatore: '.e($this->renter->name), false);
        $original = $component->get('printContext');
        $component->set('filters.organization_id', null)->set('dateFrom', '2026-10-01')
            ->set('dateTo', '2026-10-31')->assertSet('printContext', $original)
            ->call('runReport')->assertHasNoErrors()->assertSet('runError', null)
            ->assertSet('printContext.organization_names', [])
            ->assertSet('printContext.date_from', '01/10/2026');
    }

    public function test_saved_report_header_uses_the_preset_scope_and_resets_with_selection(): void
    {
        $preset = $this->preset('commissions_by_closure', 'sum_admin_fee_amount');
        $preset->filters = ['organization_id' => $this->renter->id];
        $preset->save();
        $component = Livewire::test(RunSavedPreset::class)
            ->call('selectReportPreset', $preset->id)
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-09-30')
            ->call('runReport')->assertHasNoErrors()->assertSet('runError', null)
            ->assertSet('printContext.organization_names', [$this->renter->name])
            ->assertSee('Noleggiatore: '.e($this->renter->name), false);
        $original = $component->get('printContext');
        $component->set('dateTo', '2026-10-31')->assertSet('printContext', $original)
            ->call('selectReportPreset', $preset->id)->assertSet('printContext', []);
    }

    private function preset(string $type, string $metric): ReportPreset
    {
        return new ReportPreset(['name' => 'Report di collaudo', 'report_type' => $type,
            'metrics' => [$metric], 'dimensions' => ['month'], 'chart_type' => 'table',
            'filters' => ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], 'created_by' => auth()->id()]);
    }

    private function inspectPdf(string $binary, string $name): void
    {
        $this->assertStringStartsWith('%PDF-', $binary);
        $directory = getenv('R4_QA_ARTIFACTS');
        if ($directory && is_dir($directory)) {
            file_put_contents($directory.'/'.$name, $binary);
        }
    }
}
