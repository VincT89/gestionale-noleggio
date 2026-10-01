<?php

namespace App\Http\Controllers;

use App\Services\AmdRent\PublicCustomerRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, Validator};
use Illuminate\Validation\{Rule, ValidationException};

class PublicCustomerController extends Controller
{
    private function customer(Request $request)
    {
        $customer = $request->user('public_customer');
        app(PublicCustomerRecords::class)->link($customer);
        return $customer;
    }

    public function bookings(Request $request)
    {
        $customer = $this->customer($request);
        $data = $request->validate(['period' => ['nullable', Rule::in(['all', 'upcoming', 'past'])]]);
        $period = $data['period'] ?? 'all';
        $bookings = $customer->bookings()->with('rental')
            ->when($period === 'upcoming', fn ($q) => $q->where('return_at', '>=', now()))
            ->when($period === 'past', fn ($q) => $q->where('return_at', '<', now()))
            ->latest('pickup_at')->paginate(10)->withQueryString();
        return view('public-account.bookings', compact('customer', 'bookings', 'period'));
    }

    public function booking(Request $request, string $reference)
    {
        $this->customer($request)->bookings()->where('reference', $reference)->firstOrFail();
        return app(PublicBookingController::class)->confirmation($reference);
    }

    public function pdf(Request $request, string $reference)
    {
        $this->customer($request)->bookings()->where('reference', $reference)->firstOrFail();
        return app(PublicBookingController::class)->pdf($reference);
    }

    public function enquiries(Request $request)
    {
        $customer = $this->customer($request);
        $cases = $customer->enquiries()->latest()->paginate(10);
        return view('public-account.enquiries', compact('customer', 'cases'));
    }

    public function enquiry(Request $request, string $reference)
    {
        $case = $this->customer($request)->enquiries()->with(['quotes', 'booking', 'documents' => fn ($q) => $q->where('customer_visible', true)->latest()])->where('reference', $reference)->firstOrFail();
        return view('public-cars.enquiry', ['case' => $case, 'customerArea' => true]);
    }

    public function upload(Request $request, string $reference)
    {
        $customer = $this->customer($request);
        $case = $customer->enquiries()->where('reference', $reference)->where('type', 'long_term')->firstOrFail();
        abort_unless(in_array($case->status, ['new', 'working', 'quoted', 'accepted']), 409, 'La pratica è conclusa. Contatta l’assistenza per nuovi documenti.');
        $validator = Validator::make($request->all(), ['kind' => ['required', Rule::in(['customer', 'company'])], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        if ($validator->fails()) throw (new ValidationException($validator))->redirectTo(route('public-account.enquiry', $reference));
        $data = $validator->validated();
        $file = $request->file('document');
        $path = $file->store('amd-rent/'.$case->id, 'amd_rent_private');
        abort_unless($path, 500, 'Impossibile salvare il documento. Riprova.');
        try {
            DB::transaction(function () use ($customer, $case, $data, $file, $path) {
                $locked = $customer->enquiries()->lockForUpdate()->findOrFail($case->id);
                abort_unless(in_array($locked->status, ['new', 'working', 'quoted', 'accepted']), 409);
                $locked->documents()->create(['kind' => $data['kind'], 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 191), 'path' => $path, 'size' => $file->getSize(), 'uploaded_by' => null, 'public_customer_id' => $customer->id, 'customer_visible' => true]);
                $locked->events()->create(['user_id' => null, 'description' => 'Documento caricato dal cliente nella propria area riservata.']);
            });
        } catch (\Throwable $e) { Storage::disk('amd_rent_private')->delete($path); throw $e; }
        return redirect()->route('public-account.enquiry', $reference)->with('status', 'Documento inviato. È disponibile per chi segue la tua pratica.');
    }

    public function download(Request $request, string $reference, int $document)
    {
        $case = $this->customer($request)->enquiries()->where('reference', $reference)->firstOrFail();
        $file = $case->documents()->where('customer_visible', true)->findOrFail($document);
        abort_unless(Storage::disk('amd_rent_private')->exists($file->path), 404);
        return Storage::disk('amd_rent_private')->download($file->path, $file->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
