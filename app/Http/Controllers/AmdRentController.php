<?php

namespace App\Http\Controllers;

use App\Models\{AmdRentEnquiry, Organization, PublicBooking, PublicDeliveryLocation};
use App\Support\AmdRentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AmdRentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $enquiries = AmdRentAccess::scope(AmdRentEnquiry::query(), $user);
        $bookings = AmdRentAccess::scope(PublicBooking::query(), $user);
        return view('amd-rent.index', [
            'requests' => (clone $enquiries)->whereIn('status', ['new', 'working'])->latest()->limit(8)->get(),
            'pendingPayments' => (clone $bookings)->where('payment_status', 'pending')->count(),
            'reviewPayments' => (clone $bookings)->where('payment_status', 'review')->count(),
            'upcoming' => (clone $bookings)->where('pickup_at', '>=', now())->whereIn('payment_status', ['paid', 'pickup'])->orderBy('pickup_at')->limit(5)->get(),
            'deliveryCount' => AmdRentAccess::scope(PublicDeliveryLocation::query(), $user)->where('is_active', true)->count(),
        ]);
    }

    public function settings(Request $request)
    {
        AmdRentAccess::admin($request->user());
        return view('amd-rent.settings', ['deliveryBps' => DB::table('amd_rent_settings')->where('id', 1)->value('delivery_commission_bps'),
            'stripeReady' => app(\App\Services\AmdRent\StripeCheckout::class)->ready()]);
    }

    public function saveSettings(Request $request)
    {
        AmdRentAccess::admin($request->user());
        $data = $request->validate(['delivery_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2']]);
        DB::table('amd_rent_settings')->updateOrInsert(['id' => 1], [
            'delivery_commission_bps' => isset($data['delivery_percent']) ? (int) round($data['delivery_percent'] * 100) : null,
            'updated_at' => now(), 'created_at' => now(),
        ]);
        return back()->with('status', 'Impostazione salvata. Le prenotazioni già accettate mantengono le condizioni concordate.');
    }
}
