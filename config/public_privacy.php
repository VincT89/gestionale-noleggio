<?php

return [
    // Complete with the controller's verified information before publication.
    // These fields are public information; never put credentials here.
    // Prefilled from AMD Mobility's published policy, checked 2026-10-06.
    // The controller must confirm that these details also apply to AMD Rent.
    'controller_name' => env('AMD_RENT_PRIVACY_CONTROLLER', 'AMD MOBILITY SRLS'),
    'controller_address' => env('AMD_RENT_PRIVACY_ADDRESS', 'Via Orfeo Mazzitelli 140, 70124 Bari (BA), Italia'),
    'controller_vat' => env('AMD_RENT_PRIVACY_VAT', '08952480724'),
    'privacy_email' => env('AMD_RENT_PRIVACY_EMAIL', 'amdmobility1@gmail.com'),
    'controller_source' => 'https://www.iubenda.com/privacy-policy/91088250',
    'dpo_contact' => env('AMD_RENT_PRIVACY_DPO_CONTACT'),
    'hosting' => env('AMD_RENT_PRIVACY_HOSTING'),
    'email_provider' => env('AMD_RENT_PRIVACY_EMAIL_PROVIDER'),
    'supplier_roles' => env('AMD_RENT_PRIVACY_SUPPLIER_ROLES'),
    'international_transfers' => env('AMD_RENT_PRIVACY_TRANSFERS'),
    // Describe actual periods/criteria, including backups. This does not delete data.
    'retention_enquiries' => env('AMD_RENT_PRIVACY_RETENTION_ENQUIRIES'),
    'retention_accounts' => env('AMD_RENT_PRIVACY_RETENTION_ACCOUNTS'),
    'retention_bookings' => env('AMD_RENT_PRIVACY_RETENTION_BOOKINGS'),
    'retention_documents' => env('AMD_RENT_PRIVACY_RETENTION_DOCUMENTS'),
    'retention_logs' => env('AMD_RENT_PRIVACY_RETENTION_LOGS'),
    'reviewed' => (bool) env('AMD_RENT_PRIVACY_REVIEWED', false),
    'updated_at' => '2026-10-06',
    // Informational notice only: no optional tracking is installed by this site.
    // Review the notice and implement prior consent BEFORE adding any trackers.
    'notice_version' => '2026-10-06',
    'notice_days' => 180,
];
