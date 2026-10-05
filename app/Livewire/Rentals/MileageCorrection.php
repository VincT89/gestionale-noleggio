<?php

namespace App\Livewire\Rentals;

use App\Models\{Rental, RentalMileageCorrection};
use App\Services\Rentals\RentalMileageCorrectionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MileageCorrection extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $rentalId;

    #[Locked]
    public array $original = [];

    public bool $showModal = false;
    public $out = null;
    public $in = null;
    public bool $syncVehicle = false;
    public string $reason = '';

    public function mount(int $rentalId): void
    {
        $this->rentalId = $rentalId;
        $this->rental();
    }

    private function rental(): Rental
    {
        $rental = Rental::findOrFail($this->rentalId);
        $this->authorize('view', $rental);
        return $rental;
    }

    public function open(RentalMileageCorrectionService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
        $rental = $this->rental();
        $this->authorize('update', $rental);
        $this->resetValidation();
        $this->original = $service->context($rental);
        $this->out = $this->original['out'];
        $this->in = $this->original['in'];
        $this->syncVehicle = $this->original['can_sync_vehicle'];
        $this->reason = '';
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->resetValidation();
        $this->reset('original', 'out', 'in', 'syncVehicle', 'reason');
    }

    public function save(RentalMileageCorrectionService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && $this->showModal, 403);
        $this->rental();
        $service->correct($this->rentalId, [
            'out' => $this->out, 'in' => $this->in, 'sync_vehicle' => $this->syncVehicle, 'reason' => $this->reason,
        ], $this->original['version'] ?? '', auth()->user());
        $this->showModal = false;
        // A reload also refreshes the existing payment panel, which uses wire:ignore.
        $this->redirectRoute('rentals.show', ['rental' => $this->rentalId, 'tab' => $this->in === null ? 'pickup' : 'return']);
    }

    public function download(int $correctionId)
    {
        $rental = $this->rental();
        $correction = $this->history($rental)->findOrFail($correctionId);
        $pdf = Pdf::loadView('pdfs.mileage-correction', ['correction' => $correction])->setPaper('a4');
        return response()->streamDownload(fn () => print($pdf->output()), 'rettifica-km-'.$rental->id.'-'.$correction->id.'.pdf');
    }

    private function history(Rental $rental)
    {
        return RentalMileageCorrection::where('rental_id', $rental->id);
    }

    public function render()
    {
        $rental = $this->rental();
        $corrections = $this->history($rental)->latest('id')->get();
        $paidIds = $rental->charges()->paid()->pluck('id')->all();
        return view('livewire.rentals.mileage-correction', [
            'state' => app(RentalMileageCorrectionService::class)->context($rental),
            'corrections' => $corrections,
            'paymentReviewNeeded' => $corrections->contains(fn ($correction) =>
                ($correction->properties['payment_review'] ?? false)
                && array_intersect($paidIds, $correction->properties['recorded_payment_ids'] ?? []) !== []),
        ]);
    }
}
