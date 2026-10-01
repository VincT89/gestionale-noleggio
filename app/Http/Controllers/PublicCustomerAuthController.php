<?php

namespace App\Http\Controllers;

use App\Models\PublicCustomer;
use App\Services\AmdRent\PublicCustomerRecords;
use Illuminate\Auth\Events\{PasswordReset, Verified};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Password, RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, Rules\Password as PasswordRule, ValidationException};

class PublicCustomerAuthController extends Controller
{
    public function loginForm() { return view('public-account.login'); }
    public function registerForm() { return view('public-account.register'); }
    public function forgotForm() { return view('public-account.forgot-password'); }
    public function resetForm(Request $request, string $token)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:191']]);
        return view('public-account.reset-password', ['token' => $token, 'email' => $data['email']]);
    }

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
    }

    public static function passwordRules(): array
    {
        return ['required', 'string', 'max:128', PasswordRule::min(12)->letters()->numbers(), 'confirmed'];
    }

    private function startSession(Request $request, PublicCustomer $customer): void
    {
        Auth::guard('public_customer')->login($customer);
        $request->session()->regenerate();
        $request->session()->put('public_customer_password_hash', $customer->getAuthPassword());
    }

    private function destination(Request $request, PublicCustomer $customer)
    {
        if (!$customer->hasVerifiedEmail()) return redirect()->route('public-account.verification.notice');
        app(PublicCustomerRecords::class)->link($customer);
        $intended = $request->session()->pull('public_customer_intended');
        if (is_string($intended) && preg_match('#^/area-cliente(?:/|\?|$)#', $intended)) {
            return redirect()->to($intended);
        }
        return redirect()->route('public-account.bookings');
    }

    public function login(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:191'], 'password' => ['required', 'string', 'max:128']]);
        $key = 'public-customer-login:'.hash('sha256', $data['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['email' => 'Troppi tentativi. Riprova tra '.RateLimiter::availableIn($key).' secondi.']);
        if (!Auth::guard('public_customer')->attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email o password non corrette.']);
        }
        RateLimiter::clear($key);
        $customer = Auth::guard('public_customer')->user();
        $request->session()->regenerate();
        $request->session()->put('public_customer_password_hash', $customer->getAuthPassword());
        return $this->destination($request, $customer);
    }

    public function register(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:90'], 'last_name' => ['required', 'string', 'max:90'],
            'email' => ['required', 'email:rfc', 'max:191', Rule::unique('public_customers')],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9().\s-]+$/'], 'password' => self::passwordRules(),
        ]);
        $customer = PublicCustomer::create($data);
        $this->startSession($request, $customer);
        return $this->sendVerification($customer);
    }

    private function sendVerification(PublicCustomer $customer)
    {
        try {
            $customer->sendEmailVerificationNotification();
            return redirect()->route('public-account.verification.notice')->with('status', 'Ti abbiamo inviato il collegamento per verificare l’email. Controlla anche la posta indesiderata.');
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('public-account.verification.notice')->withErrors(['email' => 'L’account è stato salvato, ma l’email non è partita. Riprova con “Invia di nuovo” o contatta l’assistenza.']);
        }
    }

    public function verificationNotice(Request $request)
    {
        $customer = $request->user('public_customer');
        return $customer->hasVerifiedEmail() ? $this->destination($request, $customer) : view('public-account.verify-email', compact('customer'));
    }

    public function verificationSend(Request $request)
    {
        $customer = $request->user('public_customer');
        return $customer->hasVerifiedEmail() ? $this->destination($request, $customer) : $this->sendVerification($customer);
    }

    public function verify(Request $request, string $id, string $hash)
    {
        $customer = DB::transaction(function () use ($request, $id, $hash) {
            $customer = PublicCustomer::lockForUpdate()->findOrFail($request->user('public_customer')->id);
            abort_unless((string) $customer->id === $id && hash_equals(sha1($customer->getEmailForVerification()), $hash), 403);
            if (!$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
                event(new Verified($customer));
            }
            return $customer;
        });
        return $this->destination($request, $customer)->with('status', 'Email verificata. Benvenuto nella tua area cliente.');
    }

    public function forgot(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:191']]);
        try { Password::broker('public_customers')->sendResetLink($data); }
        catch (\Throwable $e) { report($e); }
        return redirect()->route('public-account.password.request')->with('status', 'Se questa email è associata a un account AMD Rent, riceverai un collegamento per scegliere una nuova password. Controlla anche la posta indesiderata.');
    }

    public function reset(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:200'], 'email' => ['required', 'email:rfc', 'max:191'], 'password' => self::passwordRules(), 'password_confirmation' => ['required', 'string']]);
        $status = Password::broker('public_customers')->reset($data, function (PublicCustomer $customer, string $password) {
            $customer->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($customer));
        });
        if ($status !== Password::PASSWORD_RESET) throw ValidationException::withMessages(['email' => 'Il collegamento non è valido o è scaduto. Richiedi una nuova email di recupero.']);
        return redirect()->route('public-account.login')->with('status', 'Password aggiornata. Accedi con la nuova password.');
    }

    public function profile(Request $request) { return view('public-account.profile', ['customer' => $request->user('public_customer')]); }

    public function updateProfile(Request $request)
    {
        $customer = $request->user('public_customer');
        $this->normalizeEmail($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:90'], 'last_name' => ['required', 'string', 'max:90'],
            'email' => ['required', 'email:rfc', 'max:191', Rule::unique('public_customers')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9().\s-]+$/'],
        ]);
        $changed = $customer->email !== $data['email'];
        if ($changed) $request->validate(['current_password' => ['required', 'string', 'current_password:public_customer']]);
        $customer->fill($data);
        if ($changed) $customer->email_verified_at = null;
        $customer->save();
        return $changed ? $this->sendVerification($customer) : redirect()->route('public-account.profile')->with('status', 'Profilo aggiornato. I dati dei contratti già emessi restano invariati.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password:public_customer'], 'password' => array_merge(self::passwordRules(), ['different:current_password'])]);
        $customer = $request->user('public_customer');
        $customer->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate(true);
        $request->session()->put('public_customer_password_hash', $customer->getAuthPassword());
        return redirect()->route('public-account.profile')->with('status', 'Password aggiornata. Sugli altri dispositivi sarà necessario accedere di nuovo.');
    }

    public function logout(Request $request)
    {
        Auth::guard('public_customer')->logout();
        $request->session()->forget(['public_customer_password_hash', 'public_customer_intended']);
        $request->session()->regenerate(true);
        return redirect()->route('public-account.login')->with('status', 'Hai effettuato l’uscita dall’area cliente.');
    }
}
