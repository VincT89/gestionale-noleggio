<?php

namespace App\Http\Controllers;

use App\Models\{AmdRentEnquiry, AmdRentDocument, Organization};
use App\Support\AmdRentAccess;
use App\Services\AmdRent\DeliveryBookingRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

class AmdRentEnquiryController extends Controller
{
    private function query(Request $request) { return AmdRentAccess::scope(AmdRentEnquiry::query(), $request->user()); }
    private function event(AmdRentEnquiry $case, Request $request, string $message): void
    {
        $case->events()->create(['user_id' => $request->user()->id, 'description' => $message]);
    }
    public function index(Request $request)
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(['long_term', 'delivery'])], 'status' => ['nullable', Rule::in(array_keys(AmdRentEnquiry::STATUSES))], 'q' => ['nullable', 'string', 'max:100']]);
        $type = $data['type'] ?? 'long_term';
        $cases = $this->query($request)->with('organization')->where('type', $type)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['q'] ?? null, fn ($q, $value) => $q->where(fn ($sub) => $sub->where('reference', 'like', "%{$value}%")->orWhere('customer_name', 'like', "%{$value}%")))
            ->latest()->paginate(15)->withQueryString();
        return view('amd-rent.enquiries.index', compact('cases', 'type', 'data'));
    }
    public function create(Request $request)
    {
        AmdRentAccess::check($request->user(), true);
        return view('amd-rent.enquiries.create');
    }
    public function store(Request $request)
    {
        AmdRentAccess::check($request->user(), true);
        $data = $request->validate(self::contactRules() + self::longTermRules());
        $case = AmdRentEnquiry::create($data + ['type' => 'long_term', 'reference' => 'LT-'.Str::upper(Str::random(12)), 'organization_id' => $request->user()->organization_id]);
        $this->event($case, $request, 'Pratica creata.');
        return redirect()->route('amd-rent.enquiries.show', $case);
    }
    public static function contactRules(): array
    {
        return ['customer_name' => ['required', 'string', 'max:191'], 'email' => ['required', 'email:rfc', 'max:191'], 'phone' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[+0-9().\s-]+$/']];
    }
    public static function longTermRules(): array
    {
        return ['customer_type' => ['required', Rule::in(['individual', 'business'])], 'company_name' => ['nullable', 'required_if:customer_type,business', 'string', 'max:191'],
            'vehicle_request' => ['required', 'string', 'max:191'], 'duration_months' => ['required', 'integer', 'between:12,120'], 'annual_km' => ['required', 'integer', 'between:1000,200000'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
    public function show(Request $request, int $enquiry)
    {
        $case = $this->query($request)->with(['quotes', 'documents', 'events', 'organization', 'booking'])->findOrFail($enquiry);
        $organizations = $request->user()->hasRole('admin') ? Organization::where('is_active', true)->orderBy('name')->get() : collect();
        return response()->view('amd-rent.enquiries.show', compact('case', 'organizations'))->header('Cache-Control', 'private, no-store');
    }
    public function update(Request $request, int $enquiry)
    {
        AmdRentAccess::check($request->user(), true);
        $admin = $request->user()->hasRole('admin');
        $rules = ['revision' => ['required', 'integer'], 'status' => ['required', Rule::in(['new', 'working', 'quoted', 'accepted', 'signed', 'lost'])],
            'selected_quote_id' => ['nullable', 'integer'], 'contract_reference' => ['nullable', 'string', 'max:191'], 'signed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'signed_in_person' => ['sometimes', 'accepted'], 'delivery_fee' => ['nullable', 'numeric', 'between:0,100000', 'decimal:0,2'], 'quote_valid_until' => ['nullable', 'date', 'after_or_equal:today']];
        foreach (['organization_id', 'platform_commission', 'renter_commission', 'commission_status'] as $field) $rules[$field] = $admin ? ['nullable'] : ['prohibited'];
        if ($admin) $rules = array_replace($rules, ['organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'platform_commission' => ['nullable', 'numeric', 'between:0,1000000', 'decimal:0,2'], 'renter_commission' => ['nullable', 'numeric', 'between:0,1000000', 'decimal:0,2'], 'commission_status' => ['nullable', Rule::in(['expected', 'earned', 'paid'])]]);
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $enquiry, $data, $admin) {
            $case = $this->query($request)->lockForUpdate()->findOrFail($enquiry);
            abort_if($case->revision !== (int) $data['revision'], 409, 'La pratica è stata aggiornata. Ricarica la pagina.');
            abort_if(in_array($case->status, ['signed', 'converted']), 409, 'La pratica è conclusa. Lo storico resta disponibile.');
            $changes = ['status' => $data['status'], 'revision' => $case->revision + 1];
            if ($case->type === 'delivery') {
                if (!in_array($data['status'], ['new', 'working', 'quoted', 'lost'])) throw ValidationException::withMessages(['status' => 'La consegna viene accettata dal cliente durante la prenotazione.']);
                if ($data['status'] === 'quoted') {
                    if (!empty($case->booking_context['return_address'])) {
                        $request->validate(['confirm_custom_return' => ['accepted']], [
                            'confirm_custom_return.accepted' => 'Conferma anche il luogo di riconsegna richiesto e includilo nel supplemento complessivo.',
                        ]);
                    }
                    if (!isset($data['delivery_fee'], $data['quote_valid_until'])) throw ValidationException::withMessages(['delivery_fee' => 'Indica il supplemento e la validità dopo aver verificato l’indirizzo e la possibilità di consegna.']);
                    $changes += ['delivery_fee_cents' => (int) round($data['delivery_fee'] * 100), 'quote_expires_at' => \Carbon\Carbon::parse($data['quote_valid_until'])->endOfDay()];
                }
            } else {
                if ($data['status'] === 'quoted' && !$case->quotes()->exists()) throw ValidationException::withMessages(['status' => 'Aggiungi almeno un preventivo.']);
                if (in_array($data['status'], ['accepted', 'signed'])) {
                    $quote = $case->quotes()->find($data['selected_quote_id'] ?? $case->selected_quote_id);
                    if (!$quote || ($data['status'] === 'accepted' && $quote->valid_until->endOfDay()->isPast())) throw ValidationException::withMessages(['selected_quote_id' => 'Scegli un preventivo valido di questa pratica.']);
                    $changes['selected_quote_id'] = $quote->id;
                }
                if ($data['status'] === 'signed') {
                    if ((int) ($changes['selected_quote_id'] ?? 0) !== (int) $case->selected_quote_id) throw ValidationException::withMessages(['selected_quote_id' => 'Il contratto deve corrispondere al preventivo già accettato.']);
                    if ($case->status !== 'accepted' || empty($data['signed_at']) || empty($data['contract_reference']) || empty($data['signed_in_person']) || !$case->documents()->where('kind', 'contract')->exists())
                        throw ValidationException::withMessages(['status' => 'Prima accetta il preventivo. Per concludere allega il contratto firmato e indica riferimento, data e firma in presenza.']);
                    $changes += ['signed_at' => $data['signed_at'], 'contract_reference' => $data['contract_reference']];
                }
            }
            if ($admin) {
                if ($case->type === 'delivery' && array_key_exists('organization_id', $data) && (int) $data['organization_id'] !== (int) $case->organization_id) throw ValidationException::withMessages(['organization_id' => 'La consegna appartiene al noleggiatore dell’auto scelta.']);
                if (array_key_exists('organization_id', $data)) $changes['organization_id'] = $data['organization_id'];
                foreach (['platform_commission', 'renter_commission'] as $key) if (array_key_exists($key, $data)) $changes[$key.'_cents'] = isset($data[$key]) ? (int) round($data[$key] * 100) : null;
                if (isset($data['commission_status'])) $changes['commission_status'] = $data['commission_status'];
            }
            $case->update($changes);
            $this->event($case, $request, 'Pratica aggiornata: '.$case->status_label.'.');
        });
        return back()->with('status', 'Pratica aggiornata.');
    }
    public function reopenDelivery(Request $request, int $enquiry, DeliveryBookingRecovery $recovery)
    {
        AmdRentAccess::check($request->user(), true);
        $case = $this->query($request)->where('type', 'delivery')->findOrFail($enquiry);
        $data = $request->validate(['booking_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1']]);
        try {
            $reopened = $recovery->reopen($case, (int) $data['booking_id'], (int) $data['revision'], $request->user()->id);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('amd-rent.enquiries.show', $case));
        }
        return redirect()->route('amd-rent.enquiries.show', $case)->with('status', $reopened
            ? 'Richiesta riaperta. La prenotazione precedente resta nello storico; proposta e scadenza sono conservate.'
            : 'La richiesta è già stata aggiornata.');
    }
    public function quote(Request $request, int $enquiry)
    {
        AmdRentAccess::check($request->user(), true);
        $data = $request->validate(['supplier' => ['required', 'string', 'max:191'], 'vehicle' => ['required', 'string', 'max:191'], 'months' => ['required', 'integer', 'between:12,120'], 'annual_km' => ['required', 'integer', 'between:1000,200000'],
            'monthly' => ['required', 'numeric', 'between:0.01,100000', 'decimal:0,2'], 'upfront' => ['required', 'numeric', 'between:0,1000000', 'decimal:0,2'], 'vat' => ['required', Rule::in(['included', 'excluded'])], 'valid_until' => ['required', 'date', 'after_or_equal:today'], 'conditions' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($request, $enquiry, $data) {
            $case = $this->query($request)->lockForUpdate()->findOrFail($enquiry);
            abort_unless($case->type === 'long_term' && in_array($case->status, ['new', 'working', 'quoted']), 409);
            $case->quotes()->create(collect($data)->except(['monthly', 'upfront'])->all() + ['monthly_cents' => (int) round($data['monthly'] * 100), 'upfront_cents' => (int) round($data['upfront'] * 100)]);
            $case->update(['status' => 'quoted', 'revision' => $case->revision + 1]);
            $this->event($case, $request, 'Nuovo preventivo della società '.$data['supplier'].'.');
        });
        return back()->with('status', 'Preventivo aggiunto allo storico.');
    }
    public function commissions(Request $request, int $enquiry)
    {
        AmdRentAccess::admin($request->user());
        $data = $request->validate(['revision' => ['required', 'integer'], 'platform_commission' => ['nullable', 'numeric', 'between:0,1000000', 'decimal:0,2'],
            'renter_commission' => ['nullable', 'numeric', 'between:0,1000000', 'decimal:0,2'], 'commission_status' => ['required', Rule::in(['expected', 'earned', 'paid'])]]);
        DB::transaction(function () use ($request, $enquiry, $data) {
            $case = $this->query($request)->where('type', 'long_term')->lockForUpdate()->findOrFail($enquiry);
            abort_if($case->revision !== (int) $data['revision'], 409, 'La pratica è stata aggiornata. Ricarica la pagina.');
            $case->update(['platform_commission_cents' => isset($data['platform_commission']) ? (int) round($data['platform_commission'] * 100) : null,
                'renter_commission_cents' => isset($data['renter_commission']) ? (int) round($data['renter_commission'] * 100) : null,
                'commission_status' => $data['commission_status'], 'revision' => $case->revision + 1]);
            $this->event($case, $request, 'Commissioni aggiornate dall’amministratore.');
        });
        return back()->with('status', 'Commissioni aggiornate.');
    }
    public function upload(Request $request, int $enquiry)
    {
        AmdRentAccess::check($request->user(), true);
        $case = $this->query($request)->findOrFail($enquiry);
        abort_unless($case->type === 'long_term', 404);
        $data = $request->validate(['kind' => ['required', Rule::in(['customer', 'company', 'quote', 'contract'])], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'customer_visible' => ['sometimes', 'boolean']]);
        $file = $request->file('document');
        $path = $file->store('amd-rent/'.$case->id, 'amd_rent_private');
        abort_unless($path, 500, 'Impossibile salvare il documento.');
        try {
            DB::transaction(function () use ($request, $case, $data, $file, $path) {
                $locked = $this->query($request)->lockForUpdate()->findOrFail($case->id);
                $locked->documents()->create(['kind' => $data['kind'], 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 191), 'path' => $path, 'size' => $file->getSize(), 'uploaded_by' => $request->user()->id, 'customer_visible' => (bool) ($data['customer_visible'] ?? false)]);
                $this->event($locked, $request, 'Documento allegato alla pratica.');
            });
        } catch (\Throwable $e) { Storage::disk('amd_rent_private')->delete($path); throw $e; }
        return back()->with('status', 'Documento salvato nell’area riservata.');
    }
    public function download(Request $request, int $enquiry, int $document)
    {
        $case = $this->query($request)->findOrFail($enquiry);
        $file = $case->documents()->findOrFail($document);
        return Storage::disk('amd_rent_private')->download($file->path, $file->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function documentVisibility(Request $request, int $enquiry, int $document)
    {
        AmdRentAccess::check($request->user(), true);
        $data = $request->validate(['customer_visible' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $enquiry, $document, $data) {
            $case = $this->query($request)->lockForUpdate()->findOrFail($enquiry);
            $file = $case->documents()->findOrFail($document);
            $file->update(['customer_visible' => (bool) $data['customer_visible']]);
            $this->event($case, $request, $data['customer_visible'] ? 'Documento condiviso nell’area cliente.' : 'Documento rimosso dalla condivisione nell’area cliente.');
        });
        return back()->with('status', 'Visibilità del documento aggiornata.');
    }
}
