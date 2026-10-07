<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\PublicBookingTestCase;

class PublicPrivacyTest extends PublicBookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['public_cars.privacy_url' => null]);
        foreach (array_keys(config('public_privacy')) as $field) {
            if (!in_array($field, ['updated_at', 'notice_version', 'notice_days'], true)) {
                config(['public_privacy.'.$field => null]);
            }
        }
    }

    public function test_legal_pages_are_public_and_incomplete_details_are_never_presented_as_final(): void
    {
        foreach (['privacy', 'cookies'] as $page) {
            $this->get(route('public-site.'.$page))->assertOk()
                ->assertSee('Informativa in preparazione')
                ->assertSee('Ragione sociale da completare')
                ->assertSee('Email per le richieste privacy da completare')
                ->assertSee(route('public-site.privacy'))
                ->assertSee(route('public-site.cookies'));
        }
        Http::assertNothingSent();
    }

    public function test_missing_information_cannot_be_hidden_by_the_review_flag_alone(): void
    {
        config(['public_privacy.reviewed' => true, 'public_privacy.controller_name' => 'Operatore dimostrativo']);
        $this->get(route('public-site.privacy'))->assertOk()
            ->assertSee('Operatore dimostrativo')->assertSee('Informativa in preparazione');
    }

    public function test_verified_information_is_rendered_as_text_and_requires_review(): void
    {
        foreach (array_keys(config('public_privacy')) as $field) {
            if (!in_array($field, ['updated_at', 'notice_version', 'notice_days', 'reviewed'], true)) {
                config(['public_privacy.'.$field => 'Dato dimostrativo per '.$field]);
            }
        }
        config(['public_privacy.controller_name' => '<script>alert("demo")</script>',
            'public_privacy.privacy_email' => 'privacy@example.test']);
        $this->get(route('public-site.privacy'))->assertOk()->assertSee('Informativa in preparazione');
        config(['public_privacy.reviewed' => true]);
        $this->get(route('public-site.privacy'))->assertOk()
            ->assertDontSee('Informativa in preparazione')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false)
            ->assertSee('mailto:privacy@example.test', false);
        config(['public_privacy.privacy_email' => 'invalid-address']);
        $this->get(route('public-site.privacy'))->assertOk()->assertSee('Informativa in preparazione')
            ->assertDontSee('mailto:invalid-address', false);
    }

    public function test_cookie_durations_follow_runtime_session_settings(): void
    {
        config(['session.cookie' => 'demo_session', 'session.lifetime' => 37, 'session.expire_on_close' => false]);
        $this->get(route('public-site.cookies'))->assertOk()
            ->assertSee('demo_session')->assertSee('XSRF-TOKEN')
            ->assertSee('37 minuti')->assertSee('amd-rent.cookie-notice')->assertSee('180 giorni');
        config(['session.expire_on_close' => true]);
        $this->get(route('public-site.cookies'))->assertOk()->assertSee('Fino alla chiusura del browser.')
            ->assertSee('37 minuti'); // CSRF cookie has its own expiration.
    }

    public function test_legal_information_is_accessible_after_the_search_limit_is_exhausted(): void
    {
        for ($i = 0; $i < 60; $i++) $this->get(route('public-site.support'))->assertOk();
        $this->get(route('public-site.support'))->assertStatus(429);
        $this->get(route('public-site.privacy'))->assertOk();
        $this->get(route('public-site.cookies'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_public_forms_link_the_notice_without_requiring_marketing_consent(): void
    {
        foreach (['public-account.register', 'public-site.long-term'] as $route) {
            $response = $this->get(route($route))->assertOk()
                ->assertSee('href="'.route('public-site.privacy').'" target="_blank"', false)
                ->assertSee('Informativa cookie')->assertSee('Ho capito')
                ->assertDontSee('Accetta tutti')->assertDontSee('name="marketing_consent"', false);
            $this->assertStringNotContainsString('consent=granted', $response->getContent());
        }
    }
}
