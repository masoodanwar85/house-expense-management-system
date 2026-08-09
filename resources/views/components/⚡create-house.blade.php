<?php

use App\Exceptions\DomainException;
use App\Services\House\HouseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $description = '';

    public string $currency = 'PKR';

    public function save(HouseService $houses): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        try {
            $house = $houses->create(Auth::user(), $validated);
        } catch (DomainException|ValidationException $e) {
            $this->addError('name', $e instanceof DomainException ? $e->getMessage() : 'Could not create house.');

            return;
        }

        $this->dispatch('house-created', houseId: $house->id);
        $this->reset(['name', 'description']);
        $this->currency = 'PKR';
    }
};
?>

<div class="panel">
    <h2>Create a house</h2>
    <p class="muted">Start a household ledger. You become the owner.</p>

    <form wire:submit="save">
        <label for="house_name">Name</label>
        <input id="house_name" type="text" wire:model="name" placeholder="Family House">
        @error('name') <div class="error">{{ $message }}</div> @enderror

        <label for="house_description">Description</label>
        <textarea id="house_description" rows="2" wire:model="description"></textarea>

        <label for="house_currency">Currency</label>
        <input id="house_currency" type="text" wire:model="currency" maxlength="3">
        @error('currency') <div class="error">{{ $message }}</div> @enderror

        <button class="btn" type="submit">Create house</button>
    </form>
</div>
