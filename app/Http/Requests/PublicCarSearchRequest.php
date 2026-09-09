<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Vehicle;

class PublicCarSearchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('public-cars.preview*') ? 'public-cars.preview.index' : 'public-cars.index');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['budget' => is_string($this->budget) ? str_replace(',', '.', $this->budget) : $this->budget]);
    }

    public function rules(): array
    {
        $required = $this->filled('pickup_at') || $this->filled('return_at') || $this->route('offer');

        return [
            'pickup_at' => ['bail', $required ? 'required' : 'nullable', 'string', 'date_format:Y-m-d\TH:i', 'after_or_equal:'.now()->format('Y-m-d\TH:i')],
            'return_at' => ['bail', $required ? 'required' : 'nullable', 'string', 'date_format:Y-m-d\TH:i'],
            'city' => ['nullable', 'string', 'max:100'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:20'],
            'transmission' => ['nullable', Rule::in(array_keys(Vehicle::TRANSMISSION_LABELS_IT))],
            'fuel_type' => ['nullable', Rule::in(array_keys(Vehicle::FUEL_TYPE_LABELS_IT))],
            'segment' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['price_asc', 'price_desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('pickup_at') || $validator->errors()->has('return_at')
                || !$this->filled('pickup_at') || !$this->filled('return_at')) {
                return;
            }
            $start = CarbonImmutable::parse($this->pickup_at, config('app.timezone'));
            $end = CarbonImmutable::parse($this->return_at, config('app.timezone'));
            if ($end <= $start) {
                $validator->errors()->add('return_at', 'La riconsegna deve essere successiva al ritiro.');
                return;
            }
            if ($end > $start->addDays(config('public_cars.max_rental_days'))) {
                $validator->errors()->add('return_at', 'Per periodi superiori a '.config('public_cars.max_rental_days').' giorni, contattaci per un preventivo.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'pickup_at.required' => 'Indica data e ora del ritiro.',
            'pickup_at.string' => 'Controlla data e ora del ritiro.',
            'pickup_at.date_format' => 'Controlla data e ora del ritiro.',
            'pickup_at.after_or_equal' => 'Il ritiro deve essere nel futuro.',
            'return_at.required' => 'Indica data e ora della riconsegna.',
            'return_at.string' => 'Controlla data e ora della riconsegna.',
            'return_at.date_format' => 'Controlla data e ora della riconsegna.',
            'budget.*' => 'Inserisci un budget valido in euro, con al massimo due decimali.',
        ];
    }
}
