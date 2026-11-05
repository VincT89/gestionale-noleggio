@php $contactValue = fn ($field) => is_scalar($value = old($field, $customer?->$field ?? '')) ? $value : ''; @endphp
<div class="amd-account-grid">
    @foreach(['first_name' => 'Nome', 'last_name' => 'Cognome'] as $field => $label)
    <div class="amd-field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ $contactValue($field) }}" autocomplete="{{ $field === 'first_name' ? 'given-name' : 'family-name' }}" maxlength="90" required></div>
    @endforeach
</div>
<div class="amd-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ $contactValue('email') }}" autocomplete="email" maxlength="191" required></div>
<div class="amd-field"><label for="phone">Telefono (facoltativo)</label><input id="phone" type="tel" name="phone" value="{{ $contactValue('phone') }}" autocomplete="tel" maxlength="32"></div>
