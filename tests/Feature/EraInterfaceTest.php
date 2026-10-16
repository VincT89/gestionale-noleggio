<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireModernEra;
use App\Models\AmdRentEnquiry;
use App\Support\EraInterface;
use Illuminate\Support\Facades\{DB, Route};
use Tests\Support\PublicBookingTestCase;

class EraInterfaceTest extends PublicBookingTestCase
{
    public function test_presentations_and_compiled_components_do_not_leak_between_roles(): void
    {
        $admin = $this->publisher();
        $renter = $this->publisher(2, 'renter');

        foreach ([$admin, $renter, $admin, $renter] as $user) {
            $this->actingAs($user);
            $modern = $user->hasRole('admin');
            $html = $this->view('dashboard');
            $html->assertSee('data-era-interface="'.($modern ? 'modern' : 'classic').'"', false);
            if ($modern) {
                $html->assertSee('Pratiche e richieste')->assertDontSee('era-classic/app-6If3uo48.css');
            } else {
                $html->assertSee('era-classic/app-6If3uo48.css')
                    ->assertDontSee('Pratiche e richieste')->assertDontSee('Luoghi di consegna')
                    ->assertDontSee('Impostazioni AMD Rent')->assertSee('Stampa checklist vuota');
            }
        }
    }

    public function test_all_new_management_routes_require_admin_even_with_extra_permissions(): void
    {
        $renter = $this->publisher(2, 'renter');
        DB::table('permissions')->insert(['id' => 70, 'name' => 'manage.renters']);
        DB::table('role_has_permissions')->insert(['role_id' => 2, 'permission_id' => 70]);
        $this->actingAs($renter);
        $case = AmdRentEnquiry::create(['reference' => 'LT-ROLLOUT-TEST', 'type' => 'long_term', 'organization_id' => 2,
            'customer_name' => 'Cliente dimostrativo', 'email' => 'rollout@example.test', 'phone' => '0000000000',
            'vehicle_request' => 'Auto dimostrativa', 'duration_months' => 12, 'annual_km' => 10000]);
        foreach (['amd-rent.index', 'amd-rent.settings', 'amd-rent.enquiries.index', 'amd-rent.enquiries.create',
            'public-bookings.index', 'fleet-products.index', 'public-offers.index', 'public-deliveries.index', 'public-cars.preview.index'] as $name) {
            $this->get(route($name))->assertForbidden();
        }
        $this->get(route('amd-rent.enquiries.show', $case))->assertForbidden();
        $this->put(route('amd-rent.enquiries.update', $case), ['revision' => 1, 'status' => 'working'])->assertForbidden();
        $this->post(route('public-deliveries.store'), [])->assertForbidden();
        $this->post(route('fleet-products.store'), ['name' => 'Tipo non autorizzato'])->assertForbidden();
        $this->assertSame('new', $case->fresh()->status);

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if ((str_starts_with($name, 'amd-rent.') && $name !== 'amd-rent.stripe.webhook')
                || str_starts_with($name, 'fleet-products.') || str_starts_with($name, 'public-deliveries.')
                || str_starts_with($name, 'public-cars.preview.') || in_array($name, ['public-bookings.index', 'public-offers.index'])) {
                $this->assertContains(RequireModernEra::class, $route->gatherMiddleware(), $name);
            }
        }
    }

    public function test_admin_keeps_new_management_screens(): void
    {
        $this->actingAs($this->publisher());
        foreach (['amd-rent.index', 'amd-rent.settings', 'amd-rent.enquiries.index', 'public-bookings.index',
            'fleet-products.index', 'public-deliveries.index'] as $name) {
            $this->get(route($name))->assertOk()->assertSee('data-era-interface="modern"', false);
        }
    }

    public function test_authenticated_guard_and_role_changes_are_checked_on_every_request(): void
    {
        $admin = $this->publisher();
        $renter = $this->publisher(2, 'renter');
        foreach ([$admin, $renter, $admin] as $user) {
            $this->flushSession();
            $this->actingAs($user);
            $this->get(route('amd-rent.index'))->assertStatus($user->hasRole('admin') ? 200 : 403);
            $this->assertSame(!$user->hasRole('admin'), EraInterface::classic());
        }
    }

    public function test_public_site_is_unchanged_with_a_renter_session(): void
    {
        $this->actingAs($this->publisher(2, 'renter'));
        $this->get(route('public-cars.index'))->assertOk()
            ->assertDontSee('data-era-interface="classic"', false)->assertDontSee('era-classic/app-6If3uo48.css');
        $this->assertFalse(EraInterface::classic());
    }

    public function test_livewire_requests_keep_classic_views_and_shared_payment_protection(): void
    {
        $renter = $this->publisher(2, 'renter');
        $this->actingAs($renter);
        $request = request();
        $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('POST', 'livewire/update', fn () => null))->name('livewire.update'));
        $this->assertStringContainsString('era-classic', view('livewire.vehicles.form')->getPath());
        // These views deliberately stay shared so old presentation cannot remove
        // Stripe safeguards or the agreed return address.
        foreach (['pages.rentals.partials.actions', 'pages.rentals.partials.payment-history', 'pages.rentals.partials.tab-data'] as $name) {
            $this->assertStringNotContainsString('era-classic', view($name)->getPath());
        }
    }
}
