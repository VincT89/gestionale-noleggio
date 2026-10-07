<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PublicLegalController extends Controller
{
    public function privacy(): View
    {
        return view('public-legal.privacy', $this->details());
    }

    public function cookies(): View
    {
        return view('public-legal.cookies', $this->details());
    }

    private function details(): array
    {
        $privacy = config('public_privacy');
        $required = [
            'controller_name', 'controller_address', 'privacy_email', 'hosting',
            'email_provider', 'supplier_roles', 'international_transfers',
            'retention_enquiries', 'retention_accounts', 'retention_bookings',
            'retention_documents', 'retention_logs',
        ];
        $draft = !$privacy['reviewed'];
        foreach ($required as $field) {
            if (!filled($privacy[$field] ?? null)) $draft = true;
        }
        if (!filter_var($privacy['privacy_email'], FILTER_VALIDATE_EMAIL)) $draft = true;

        return compact('privacy', 'draft');
    }
}
