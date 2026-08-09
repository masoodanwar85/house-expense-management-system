<?php

use App\Exceptions\DomainException;
use App\Models\House;
use App\Services\Availability\AvailabilityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public int $houseId;

    public string $start_date = '';

    public string $end_date = '';

    public string $status = 'available';

    public string $successMessage = '';

    public function mount(int $houseId): void
    {
        $this->houseId = $houseId;
        $this->start_date = now()->toDateString();
    }

    public function save(AvailabilityService $availability): void
    {
        $this->successMessage = '';

        $validated = $this->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'in:available,unavailable'],
        ]);

        if (($validated['end_date'] ?? '') === '') {
            $validated['end_date'] = null;
        }

        try {
            $house = House::query()->findOrFail($this->houseId);
            $availability->create($house, Auth::user(), $validated);
        } catch (DomainException $e) {
            $this->addError('start_date', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->successMessage = 'Availability period saved.';
        $this->dispatch('data-updated');
    }
};
?>

<div class="panel">
    <h2>Add availability</h2>
    <p class="muted">Mark presence for allocation rules. Leave end empty if still ongoing.</p>

    @if ($successMessage)
        <p class="success">{{ $successMessage }}</p>
    @endif

    <form wire:submit="save">
        <div class="grid-2">
            <div>
                <label for="start_date">Start date</label>
                <input id="start_date" type="date" wire:model="start_date">
                @error('start_date') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="end_date">End date (optional)</label>
                <input id="end_date" type="date" wire:model="end_date">
                @error('end_date') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        <label for="status">Status</label>
        <select id="status" wire:model="status">
            <option value="available">Available</option>
            <option value="unavailable">Unavailable</option>
        </select>
        @error('status') <div class="error">{{ $message }}</div> @enderror

        <button class="btn" type="submit">Save availability</button>
    </form>
</div>
