@php
    $accountDefaults = [];
    if (request()->routeIs('public-site.*') && ($publicCustomer = auth('public_customer')->user())?->hasVerifiedEmail()) {
        $accountDefaults = ['customer_name' => $publicCustomer->name, 'email' => $publicCustomer->email, 'phone' => $publicCustomer->phone];
    }
    $v = fn ($name, $default = '') => is_scalar(old($name, $accountDefaults[$name] ?? $default)) ? old($name, $accountDefaults[$name] ?? $default) : '';
@endphp
<div class="amd-booking-fields amr-grid">
@foreach(['customer_name' => ['Nome e cognome del referente', 'text', 191], 'email' => ['Email', 'email', 191], 'phone' => ['Telefono', 'tel', 32]] as $field => [$label, $inputType, $max])<div class="amd-field"><label for="case-{{ $field }}">{{ $label }}</label><input class="app-field" id="case-{{ $field }}" name="{{ $field }}" type="{{ $inputType }}" maxlength="{{ $max }}" value="{{ $v($field) }}" required></div>@endforeach
<div class="amd-field"><label for="customer-type">Il noleggio è per</label><select class="app-field" id="customer-type" name="customer_type"><option value="individual" @selected($v('customer_type') === 'individual')>Privato</option><option value="business" @selected($v('customer_type') === 'business')>Azienda o professionista</option></select></div>
<div class="amd-field"><label for="company-name">Ragione sociale, se azienda</label><input class="app-field" id="company-name" name="company_name" value="{{ $v('company_name') }}" maxlength="191"></div>
<div class="amd-field"><label for="vehicle-request">Auto o tipologia desiderata</label><input class="app-field" id="vehicle-request" name="vehicle_request" value="{{ $v('vehicle_request') }}" maxlength="191" required></div>
<div class="amd-field"><label for="duration-months">Durata desiderata (mesi)</label><input class="app-field" id="duration-months" name="duration_months" type="number" min="12" max="120" value="{{ $v('duration_months') }}" required></div>
<div class="amd-field"><label for="annual-km">Chilometri previsti all’anno</label><input class="app-field" id="annual-km" name="annual_km" type="number" min="1000" max="200000" step="1" value="{{ $v('annual_km') }}" required></div>
</div>
<div class="amd-field"><label for="case-notes">Esigenze o informazioni aggiuntive (facoltativo)</label><textarea class="app-field" id="case-notes" name="notes" maxlength="5000" rows="4">{{ $v('notes') }}</textarea></div>
