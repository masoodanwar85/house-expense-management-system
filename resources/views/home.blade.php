<x-layouts.app :title="'House Expenses'">
    <section class="hero-home">
        <p class="brand-mark">House Expenses</p>
        <p class="muted">
            Rule-driven household accounting — versioned allocation rules, availability-aware shares,
            and clear month-end settlements.
        </p>
        <div class="cta">
            @auth
                <a class="btn" href="{{ route('dashboard') }}">Open dashboard</a>
            @else
                <a class="btn" href="{{ route('register') }}">Get started</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Login</a>
            @endauth
        </div>
    </section>
</x-layouts.app>
