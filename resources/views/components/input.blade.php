@props(['disabled' => false])

@if($attributes->get('type') === 'password')
    <x-password-input :disabled="$disabled" {{ $attributes->except('type') }} />
@else
    <input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) !!}>
@endif
