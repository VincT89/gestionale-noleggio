<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Blade, DB, Route, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Livewire\{Component, Livewire};
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\PublicBookingTestCase;

class AccountAccessTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Livewire::component('account-access-probe', AccountAccessProbe::class);
        Route::middleware(['web', 'auth'])->get('/account-access-probe', fn () =>
            Blade::render('@livewire("account-access-probe")'));
    }

    private function document(int $organization): Media
    {
        Storage::fake('local');
        $id = DB::table('media')->insertGetId([
            'model_type' => Organization::class, 'model_id' => $organization,
            'collection_name' => 'signature_company', 'name' => 'Documento dimostrativo',
            'file_name' => 'access-test.txt', 'mime_type' => 'text/plain', 'disk' => 'local',
            'conversions_disk' => 'local', 'size' => 12,
        ]);
        $media = Media::findOrFail($id);
        Storage::disk('local')->put($media->getPathRelativeToRoot(), 'TEST FIXTURE');

        return $media;
    }

    private function login($user, array $extra = [])
    {
        return $this->post('/login', array_merge([
            'email' => $user->email, 'password' => 'test-password',
        ], $extra));
    }

    private function enableTwoFactor($user): void
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['fixture-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_active_renter_can_open_own_organization_document(): void
    {
        $this->actingAs($this->publisher(2, 'renter'))
            ->get(route('media.open', $this->document(2)))->assertOk();
    }

    public function test_renter_cannot_open_another_organization_document(): void
    {
        $this->actingAs($this->publisher(2, 'renter'))
            ->get(route('media.open', $this->document(3)))->assertForbidden();
    }

    public function test_active_admin_can_open_another_organization_document(): void
    {
        $this->actingAs($this->publisher(1, 'admin'))
            ->get(route('media.open', $this->document(3)))->assertOk();
    }

    public function test_guest_cannot_open_organization_document(): void
    {
        $this->get(route('media.open', $this->document(2)))->assertRedirect(route('login'));
    }

    public function test_active_renter_can_log_in(): void
    {
        $user = $this->publisher(2, 'renter');
        $this->login($user)->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_renter_cannot_log_in_even_with_remember_me(): void
    {
        $user = $this->publisher(2, 'renter');
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->login($user, ['remember' => true])->assertForbidden();
        $this->assertGuest();
    }

    public function test_inactive_admin_cannot_log_in(): void
    {
        $user = $this->publisher(1, 'admin');
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->login($user)->assertForbidden();
        $this->assertGuest();
    }

    public function test_renter_of_inactive_organization_cannot_log_in(): void
    {
        $user = $this->publisher(2, 'renter');
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->login($user)->assertForbidden();
        $this->assertGuest();
    }

    public function test_renter_of_archived_organization_cannot_log_in(): void
    {
        $user = $this->publisher(2, 'renter');
        DB::table('organizations')->where('id', 2)->update(['deleted_at' => now()]);
        $this->login($user)->assertForbidden();
        $this->assertGuest();
    }

    public function test_incorrect_password_does_not_disclose_suspended_account_status(): void
    {
        $user = $this->publisher(2, 'renter');
        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_in_database_blocks_an_existing_user_session(): void
    {
        $user = $this->publisher(2, 'renter');
        $media = $this->document(2);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->get(route('media.open', $media))->assertForbidden()->assertSee('Accesso sospeso');
        $this->assertGuest();
    }

    public function test_deactivation_blocks_an_existing_admin_session(): void
    {
        $user = $this->publisher(1, 'admin');
        $media = $this->document(3);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->get(route('media.open', $media))->assertForbidden();
        $this->assertGuest();
    }

    public function test_deactivation_in_database_blocks_an_existing_organization_session(): void
    {
        $user = $this->publisher(2, 'renter');
        $user->load('organization');
        $media = $this->document(2);
        $this->actingAs($user);
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->get(route('media.open', $media))->assertForbidden();
        $this->assertGuest();
    }

    public function test_archived_organization_still_blocks_existing_session(): void
    {
        $user = $this->publisher(2, 'renter');
        $media = $this->document(2);
        $this->actingAs($user);
        DB::table('organizations')->where('id', 2)->update(['deleted_at' => now()]);
        $this->get(route('media.open', $media))->assertForbidden();
        $this->assertGuest();
    }

    public function test_archived_user_cannot_continue_an_existing_session(): void
    {
        $user = $this->publisher(2, 'renter');
        $media = $this->document(2);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);
        $this->get(route('media.open', $media))->assertForbidden();
        $this->assertGuest();
    }

    public function test_active_admin_keeps_management_access_if_organization_is_archived(): void
    {
        $user = $this->publisher(1, 'admin');
        DB::table('organizations')->where('id', 1)->update(['deleted_at' => now(), 'is_active' => false]);
        $this->login($user)->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get(route('media.open', $this->document(3)))->assertOk();
    }

    public function test_account_can_log_in_after_it_is_reactivated(): void
    {
        $user = $this->publisher(2, 'renter');
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->login($user)->assertForbidden();
        DB::table('organizations')->where('id', 2)->update(['is_active' => true]);
        $this->login($user)->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_suspended_user_cannot_start_two_factor_challenge(): void
    {
        $user = $this->publisher(2, 'renter');
        $this->enableTwoFactor($user);
        $user->forceFill(['is_active' => false])->save();
        $this->login($user)->assertForbidden()->assertSessionMissing('login.id');
        $this->assertGuest();
    }

    public function test_active_user_can_complete_two_factor_login(): void
    {
        $user = $this->publisher(2, 'renter');
        $this->enableTwoFactor($user);
        $this->login($user)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['recovery_code' => 'fixture-recovery-code'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_deactivated_during_two_factor_cannot_complete_login(): void
    {
        $user = $this->publisher(2, 'renter');
        $this->enableTwoFactor($user);
        $codes = $user->two_factor_recovery_codes;
        $this->login($user)->assertRedirect(route('two-factor.login'));
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->post('/two-factor-challenge', ['recovery_code' => 'fixture-recovery-code'])
            ->assertForbidden()->assertSessionMissing('login.id');
        $this->assertGuest();
        $this->assertSame($codes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_organization_deactivated_during_two_factor_cannot_complete_login(): void
    {
        $user = $this->publisher(2, 'renter');
        $this->enableTwoFactor($user);
        $this->login($user)->assertRedirect(route('two-factor.login'));
        DB::table('organizations')->where('id', 2)->update(['is_active' => false]);
        $this->post('/two-factor-challenge', ['recovery_code' => 'fixture-recovery-code'])
            ->assertForbidden()->assertSessionMissing('login.id');
        $this->assertGuest();
    }

    public function test_suspended_user_cannot_use_the_authenticated_api(): void
    {
        $user = $this->publisher(2, 'renter');
        Sanctum::actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->getJson('/api/user')->assertForbidden()->assertJsonPath('message', 'Accesso sospeso.');
    }

    public function test_active_user_can_use_the_authenticated_api(): void
    {
        $user = $this->publisher(2, 'renter');
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_livewire_rejects_changes_after_the_user_is_deactivated(): void
    {
        $this->assertLivewireDeactivation('users');
    }

    public function test_livewire_rejects_changes_after_the_organization_is_deactivated(): void
    {
        $this->assertLivewireDeactivation('organizations');
    }

    private function assertLivewireDeactivation(string $table): void
    {
        $user = $this->publisher(2, 'renter');
        $page = $this->actingAs($user)->get('/account-access-probe')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
        DB::table($table)->where('id', $table === 'users' ? $user->id : 2)->update(['is_active' => false]);
        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])->assertForbidden();
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertGuest();
    }
}

class AccountAccessProbe extends Component
{
    public function save(): void
    {
        DB::table('users')->where('id', auth()->id())->update(['name' => 'Modifica dimostrativa']);
    }

    public function render(): string
    {
        return '<div><button wire:click="save">Salva prova</button></div>';
    }
}
