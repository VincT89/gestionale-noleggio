@props(['disabled' => false])
@php $passwordInputId = $attributes->get('id') ?: 'password-'.\Illuminate\Support\Str::uuid(); @endphp
<span class="password-field" data-password-field>
    <input type="password" id="{{ $passwordInputId }}" @disabled($disabled) {{ $attributes->except(['type', 'id'])->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>
    <button type="button" class="password-toggle" data-password-toggle aria-label="Mostra password" title="Mostra password" aria-controls="{{ $passwordInputId }}" aria-pressed="false" @disabled($disabled) hidden>
        <svg data-password-eye viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg data-password-eye-off viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3 4M6 6.5A22 22 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.4-1.4M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
    </button>
</span>
