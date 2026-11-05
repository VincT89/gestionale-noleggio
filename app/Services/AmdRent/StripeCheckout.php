<?php

namespace App\Services\AmdRent;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class StripeCheckout
{
    public function ready(): bool
    {
        $prefix = config('amd_rent.stripe_live') ? '/^(sk|rk)_live_/' : '/^(sk|rk)_test_/';
        return preg_match($prefix, (string) config('amd_rent.stripe_secret')) === 1 && filled(config('amd_rent.stripe_webhook_secret'));
    }
    public function requireReady(): void
    {
        if (!$this->ready()) throw ValidationException::withMessages(['booking' => 'Il pagamento online non è ancora disponibile. Contatta AMD Rent prima di prenotare.']);
    }
    public function create(array $payload, string $key): array { return $this->call('post', '/checkout/sessions', $payload, $key); }
    public function retrieve(string $id): array { return $this->call('get', '/checkout/sessions/'.rawurlencode($id)); }
    public function expire(string $id): array { return $this->call('post', '/checkout/sessions/'.rawurlencode($id).'/expire'); }
    private function call(string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $this->requireReady();
        $http = Http::baseUrl('https://api.stripe.com/v1')->withToken(config('amd_rent.stripe_secret'))->asForm()->acceptJson()->connectTimeout(5)->timeout(20);
        if ($key) $http = $http->withHeaders(['Idempotency-Key' => $key]);
        $response = $http->{$method}($path, $payload);
        if ($method === 'post' && $path === '/checkout/sessions' && in_array($response->status(), [400, 401, 403, 404, 422], true)) throw new CheckoutRejected('Stripe ha rifiutato l’apertura del pagamento (HTTP '.$response->status().').');
        if (!$response->successful() || !is_array($response->json())) throw new \RuntimeException('Stripe non ha confermato l’operazione (HTTP '.$response->status().').');
        return $response->json();
    }
}
