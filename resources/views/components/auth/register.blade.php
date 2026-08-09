<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create($validated);

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div class="panel" style="max-width:420px;margin:2rem auto;">
    <h1>Register</h1>
    <form wire:submit="register">
        <label for="name">Name</label>
        <input id="name" type="text" wire:model="name">
        @error('name') <div class="error">{{ $message }}</div> @enderror

        <label for="email">Email</label>
        <input id="email" type="email" wire:model="email" autocomplete="email">
        @error('email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" type="password" wire:model="password" autocomplete="new-password">
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password">

        <button class="btn" type="submit">Create account</button>
    </form>
    <p class="muted" style="margin-top:1rem;">Already registered? <a href="{{ route('login') }}">Login</a></p>
</div>
