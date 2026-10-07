<?php

namespace App\Livewire\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleMileageLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class CorrectMileage extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $vehicleId = null;

    #[Locked]
    public ?int $currentMileage = null;

    #[Locked]
    public string $vehicleLabel = '';

    public bool $showModal = false;

    public $mileage = '';

    public function mount(): void
    {
        $this->authorizeAdministrator();
    }

    #[On('open-mileage-correction')]
    public function open(int $vehicleId): void
    {
        $this->authorizeAdministrator();
        $vehicle = Vehicle::withTrashed()->findOrFail($vehicleId);
        $this->authorize('correctMileage', $vehicle);
        $this->ensureNotArchived($vehicle);

        $this->resetValidation();
        $this->vehicleId = $vehicle->id;
        $this->currentMileage = $vehicle->mileage_current;
        $this->mileage = $vehicle->mileage_current ?? '';
        $this->vehicleLabel = $vehicle->plate.' - '.$vehicle->make.' '.$vehicle->model;
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->reset('vehicleId', 'currentMileage', 'vehicleLabel', 'mileage');
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizeAdministrator();
        abort_unless($this->showModal && $this->vehicleId !== null, 403);

        $data = $this->validate([
            'mileage' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ], [
            'mileage.required' => 'Inserisci il chilometraggio corretto.',
            'mileage.integer' => 'Inserisci un numero intero di chilometri.',
            'mileage.min' => 'Il chilometraggio non può essere negativo.',
            'mileage.max' => 'Il chilometraggio inserito è troppo elevato.',
        ]);

        $changed = DB::transaction(function () use ($data): bool {
            $vehicle = Vehicle::withTrashed()->lockForUpdate()->findOrFail($this->vehicleId);
            $this->authorize('correctMileage', $vehicle);
            $this->ensureNotArchived($vehicle);

            if ($vehicle->mileage_current !== $this->currentMileage) {
                throw ValidationException::withMessages([
                    'mileage' => 'Il chilometraggio è cambiato nel frattempo. Chiudi e riapri la correzione per verificare il valore aggiornato.',
                ]);
            }

            $newMileage = (int) $data['mileage'];
            if ($vehicle->mileage_current === $newMileage) {
                return false;
            }

            $oldMileage = $vehicle->mileage_current;
            $vehicle->update(['mileage_current' => $newMileage]);
            VehicleMileageLog::create([
                'vehicle_id' => $vehicle->id,
                'mileage_old' => $oldMileage,
                'mileage_new' => $newMileage,
                'changed_by' => auth()->id(),
                'source' => 'manual',
                'notes' => 'Correzione amministratore',
                'changed_at' => now(),
            ]);

            return true;
        });

        $this->showModal = false;
        $this->dispatch('vehicle-mileage-corrected', vehicleId: $this->vehicleId);
        $this->dispatch('toast', type: $changed ? 'success' : 'info',
            message: $changed ? 'Chilometraggio corretto.' : 'Il chilometraggio è già aggiornato.');
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }

    private function ensureNotArchived(Vehicle $vehicle): void
    {
        if ($vehicle->trashed()) {
            throw ValidationException::withMessages([
                'mileage' => 'Veicolo archiviato: ripristinalo prima di correggere i km.',
            ]);
        }
    }

    public function render()
    {
        return view('livewire.vehicles.correct-mileage');
    }
}
