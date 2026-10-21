<?php

namespace App\Services\AmdRent;

use App\Models\{Organization, PublicBooking};

class SupplierContacts
{
    public static function forOrganization(?Organization $organization): array
    {
        /** Company contacts are public; user accounts, CARGOS credentials and internal fields must never enter the receipt. */
        $fields = ['name', 'legal_name', 'phone', 'email', 'address_line', 'city', 'province', 'postal_code', 'country_code'];
        return collect($fields)->mapWithKeys(fn ($field) => [$field => trim((string) $organization?->getAttribute($field))])->all();
    }

    public static function forBooking(PublicBooking $booking): array
    {
        $contacts = $booking->quote_snapshot['supplier_contacts'] ?? self::forOrganization($booking->organization);
        $phone = trim((string) ($contacts['phone'] ?? ''));
        $number = preg_replace('/[\s().-]/u', '', $phone);
        $email = trim((string) ($contacts['email'] ?? ''));
        return [
            'name' => trim((string) ($contacts['name'] ?? '')) ?: ($booking->quote_snapshot['organization'] ?? ''),
            'legal_name' => $contacts['legal_name'] ?? '',
            'phone' => $phone,
            'phone_url' => preg_match('/^\+?[0-9]{6,15}$/D', $number) ? 'tel:'.$number : null,
            'email' => $email,
            'email_url' => filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:'.rawurlencode($email) : null,
            'address' => implode(', ', array_filter(array_map(fn ($key) => trim((string) ($contacts[$key] ?? '')),
                ['address_line', 'postal_code', 'city', 'province', 'country_code']))),
        ];
    }
}
