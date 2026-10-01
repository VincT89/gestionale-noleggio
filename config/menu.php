<?php
/**
 * Configurazione Menu – Gestionale SubNoleggio
 *
 * Mapping rotte/permessi:
 * - Le voci "index" usano i permessi *.viewAny del tuo seeder
 * - Dove previsto, voci “gestionali” usano *.manage (es. vehicle_documents.manage)
 * - I componenti 'sidebar' e 'radial-grid-menu' leggono queste strutture e filtrano con Gate
 *
 * ATTENZIONE:
 * - Assicurati che i nomi rotta esistano (route:list). In caso diverso, rinomina le 'route' qui sotto.
 * - Manteniamo max 8 voci per sezione del grid radiale (offsets predefiniti).
 */

return [

    /*--------------------------------------------------------------------------
    | Sidebar (testo, senza icone)
    |--------------------------------------------------------------------------*/
    'sidebar' => [
        [
            'section' => 'Noleggi',
            'items'   => [
                ['label' => 'Contratti', 'route' => 'rentals.index', 'active' => 'rentals.*', 'permission' => 'rentals.viewAny'],
            ],
        ],
        [
            'section' => 'Flotta',
            'items'   => [
                ['label' => 'Veicoli', 'route' => 'vehicles.index', 'active' => 'vehicles.*', 'permission' => 'vehicles.viewAny'],
                ['label' => 'Prodotti della flotta', 'route' => 'fleet-products.index', 'active' => 'fleet-products.*', 'permission' => 'manage.renters'],
                ['label' => 'Assegna veicoli', 'route' => 'admin.assignments', 'permission' => 'manage.renters'],
                ['label' => 'Documenti veicolo', 'route' => 'vehicle-documents.index', 'active' => 'vehicle-documents.*', 'permission' => 'vehicle_documents.viewAny'],
            ],
        ],
        [
            'section' => 'Anagrafiche',
            'items' => [
                ['label' => 'Clienti', 'route' => 'customers.index', 'active' => 'customers.*', 'permission' => 'manage.renters'],
                ['label' => 'Sedi', 'route' => 'locations.index', 'active' => 'locations.*', 'permission' => 'locations.viewAny'],
            ],
        ],
        [
            'section' => 'AMD Rent',
            'items' => [
                ['label' => 'Panoramica', 'route' => 'amd-rent.index', 'active' => 'amd-rent.index', 'permission' => 'rentals.viewAny'],
                ['label' => 'Prenotazioni e pagamenti', 'route' => 'public-bookings.index', 'active' => 'public-bookings.index', 'permission' => 'rentals.viewAny'],
                ['label' => 'Pratiche e richieste', 'route' => 'amd-rent.enquiries.index', 'active' => 'amd-rent.enquiries.*', 'permission' => 'rentals.viewAny'],
                ['label' => 'Luoghi di consegna', 'route' => 'public-deliveries.index', 'active' => 'public-deliveries.*', 'permission' => 'vehicle_pricing.update'],
                ['label' => 'Impostazioni AMD Rent', 'route' => 'amd-rent.settings', 'active' => 'amd-rent.settings*', 'permission' => 'manage.renters'],
                ['label' => 'Apri sito pubblico', 'route' => 'public-cars.index', 'new_tab' => true],
            ],
        ],
        [
            'section' => 'Report & Audit',
            'items'   => [
                ['label' => 'Report', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => 'reports.view'],
                //['label' => 'Audit',  'route' => 'audit.index',   'permission' => 'audit.view'],
            ],
        ],
        [
            'section' => 'Amministrazione',
            'items'   => [
                ['label'=>'Renter (Organizzazioni)', 'route'=>'organizations.index', 'active'=>'organizations.*', 'permission'=>'manage.renters'],
            ],
        ],
        [
            'section' => 'Moduli di emergenza',
            'items' => [
                ['label' => 'Stampa contratto vuoto', 'route' => 'contracts.blank.print', 'permission' => 'rentals.viewAny', 'new_tab' => true],
                ['label' => 'Stampa checklist vuota', 'route' => 'checklists.blank.print', 'permission' => 'rentals.viewAny', 'new_tab' => true],
            ],
        ],
        // NB: Se in futuro introdurrai permessi per utenti/ruoli, potrai riattivare una sezione ACL qui.
    ],

    /*--------------------------------------------------------------------------
    | Grid Menu (icone + sottosezioni) – max 8 items/section per compatibilità radiale
    |--------------------------------------------------------------------------*/
    'grid_menu' => [
        [
            'section' => 'Anagrafiche',
            'icon'    => 'fa-address-book',
            'items'   => [
                ['label' => 'Clienti',  'route' => 'customers.index',  'icon' => 'fa-user',          'permission' => 'manage.renters'],
                ['label' => 'Veicoli',  'route' => 'vehicles.index',   'icon' => 'fa-car',           'permission' => 'vehicles.viewAny'],
                ['label' => 'Sedi',     'route' => 'locations.index',  'icon' => 'fa-location-dot',  'permission' => 'locations.viewAny'],
            ],
        ],
        [
            'section' => 'Noleggi',
            'icon'    => 'fa-key',
            'items'   => [
                ['label' => 'Contratti',     'route' => 'rentals.index',     'icon' => 'fa-file-contract',     'permission' => 'rentals.viewAny'],
                //['label' => 'Blocchi',       'route' => 'blocks.index',      'icon' => 'fa-ban',               'permission' => 'blocks.viewAny'],
            ],
        ],
        [
            'section' => 'Flotta',
            'icon'    => 'fa-warehouse',
            'items'   => [
                ['label' => 'Documenti Veicolo', 'route' => 'vehicle-documents.index', 'icon' => 'fa-file-shield', 'permission' => 'vehicle_documents.viewAny'],
                // ['label' => 'Gestione Documenti', 'route' => 'vehicle-documents.manage', 'icon' => 'fa-upload', 'permission' => 'vehicle_documents.manage'],
            ],
        ],
        [
            'section' => 'Report & Audit',
            'icon'    => 'fa-chart-line',
            'items'   => [
                ['label' => 'Report', 'route' => 'reports.index', 'icon' => 'fa-chart-pie',  'permission' => 'reports.view'],
                //['label' => 'Audit',  'route' => 'audit.index',   'icon' => 'fa-clipboard',  'permission' => 'audit.view'],
            ],
        ],
        [
            'section' => 'Amministrazione',
            'icon'    => 'fa-user-shield',
            'items'   => [
                ['label'=>'Renter (Organizzazioni)', 'route'=>'organizations.index', 'icon'=>'fa-building',       'permission'=>'manage.renters'],
                ['label'=>'Assegna veicoli',         'route'=>'admin.assignments',   'icon'=>'fa-people-arrows', 'permission'=>'manage.renters'],
            ],
        ],
    ],

    /*--------------------------------------------------------------------------
    | Dashboard Tiles (widget rapidi) – visibilità legata ai tuoi permessi
    |  - Le chiavi 'badge_count' le useremo nel controller per popolare i contatori
    |--------------------------------------------------------------------------*/
    'dashboard_tiles' => [
        ['label' => 'Veicoli disponibili',  'route' => 'vehicles.index',          'icon' => 'fa-car-side',     'permission' => 'vehicles.viewAny',           'badge_count' => 'vehicles_available'],
        ['label' => 'Contratti aperti',     'route' => 'rentals.index',           'icon' => 'fa-file-signature','permission' => 'rentals.viewAny',            'badge_count' => 'contracts_open'],
        ['label' => 'Assegnazioni oggi',    'route' => 'assignments.index',       'icon' => 'fa-calendar-day', 'permission' => 'assignments.viewAny',        'badge_count' => 'assignments_today'],
        ['label' => 'Blocchi attivi',       'route' => 'blocks.index',            'icon' => 'fa-triangle-exclamation','permission' => 'blocks.viewAny',     'badge_count' => 'blocks_active'],
        ['label' => 'Doc. in scadenza',     'route' => 'vehicle-documents.index', 'icon' => 'fa-exclamation-circle','permission' => 'vehicle_documents.viewAny','badge_count' => 'vehicle_docs_due'],
        ['label' => 'Clienti',              'route' => 'customers.index',         'icon' => 'fa-users',        'permission' => 'manage.renters',             'badge_count' => 'customers_total'],
    ],
];
