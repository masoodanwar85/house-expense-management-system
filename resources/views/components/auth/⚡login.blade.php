<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            $this->addError('email', 'The provided credentials are incorrect.');

            return;
        }

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div class="panel" style="max-width:420px;margin:2rem auto;">
    <h1>Login</h1>
    <form wire:submit="login">
        <label for="email">Email</label>
        <input id="email" type="email" wire:model="email" autocomplete="email">
        @error('email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" type="password" wire:model="password" autocomplete="current-password">
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <button class="btn" type="submit">Sign in</button>
    </form>
    <p class="muted" style="margin-top:1rem;">No account? <a href="{{ route('register') }}">Register</a></p>
</div>
