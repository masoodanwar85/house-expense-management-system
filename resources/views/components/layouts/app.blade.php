<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'House Expenses' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f4f7f5;
            --ink: #1c2b24;
            --muted: #5c6f66;
            --accent: #1f6f5b;
            --accent-soft: #d8efe7;
            --line: #d5e0db;
            --danger: #9b2c2c;
            --ok: #176b4a;
            --warn: #8a6d1f;
            --surface: rgba(255,255,255,.86);
        }
        body {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, #e7f3ee 0%, transparent 42%),
                radial-gradient(circle at 10% 80%, #eef5f1 0%, transparent 35%),
                linear-gradient(180deg, #f7faf8 0%, var(--bg) 100%);
            min-height: 100vh;
        }
        .shell { max-width: 1040px; margin: 0 auto; padding: 1.5rem; }
        .nav {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; padding-bottom: .75rem; border-bottom: 1px solid var(--line);
        }
        .brand {
            font-family: "Fraunces", Georgia, serif; font-size: 1.4rem; font-weight: 700;
            color: var(--accent); text-decoration: none; letter-spacing: -.02em;
        }
        .nav a, .nav button {
            color: var(--muted); text-decoration: none; background: none; border: 0; cursor: pointer; font: inherit;
        }
        .nav a:hover, .nav button:hover { color: var(--accent); }
        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(4px);
        }
        h1, h2, h3 { font-family: "Fraunces", Georgia, serif; margin: 0 0 .75rem; letter-spacing: -.02em; }
        h1 { font-size: 1.85rem; }
        h2 { font-size: 1.25rem; }
        h3 { font-size: 1.05rem; color: var(--muted); font-weight: 600; }
        label { display: block; font-size: .9rem; margin-bottom: .35rem; color: var(--muted); }
        input, select, textarea {
            width: 100%; box-sizing: border-box; border: 1px solid var(--line);
            border-radius: 10px; padding: .65rem .75rem; margin-bottom: .85rem; background: #fff;
            font: inherit; color: var(--ink);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }
        .btn {
            display: inline-block; background: var(--accent); color: #fff; border: 0;
            border-radius: 10px; padding: .7rem 1rem; cursor: pointer; font-weight: 600; font: inherit;
            text-decoration: none;
        }
        .btn:hover { filter: brightness(1.05); }
        .btn-secondary { background: #fff; color: var(--accent); border: 1px solid var(--accent); }
        .btn-danger { background: var(--danger); }
        .btn-sm { padding: .4rem .7rem; font-size: .85rem; }
        .muted { color: var(--muted); }
        .error { color: var(--danger); font-size: .85rem; margin: -.5rem 0 .75rem; }
        .success { color: var(--ok); margin-bottom: .75rem; }
        .flash { margin-bottom: .75rem; padding: .65rem .8rem; border-radius: 10px; background: var(--accent-soft); color: var(--ok); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .65rem .4rem; border-bottom: 1px solid var(--line); font-size: .95rem; vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        .positive { color: var(--ok); font-weight: 600; }
        .negative { color: var(--danger); font-weight: 600; }
        .stack { display: grid; gap: 1rem; }
        .toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; margin-bottom: 1rem; }
        .toolbar > div { min-width: 160px; flex: 1; }
        .toolbar label { margin-bottom: .25rem; }
        .toolbar input, .toolbar select { margin-bottom: 0; }
        .tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
        .tab {
            border: 1px solid var(--line); background: #fff; color: var(--muted);
            border-radius: 999px; padding: .45rem .9rem; cursor: pointer; font: inherit;
        }
        .tab.is-active { background: var(--accent); color: #fff; border-color: var(--accent); }
        .badge {
            display: inline-block; padding: .15rem .5rem; border-radius: 999px;
            font-size: .75rem; font-weight: 600; background: var(--accent-soft); color: var(--accent);
        }
        .badge-closed { background: #f3e6e6; color: var(--danger); }
        .badge-draft { background: #f4efd8; color: var(--warn); }
        .badge-confirmed { background: var(--accent-soft); color: var(--ok); }
        .category-block {
            padding: 1rem 0;
            border-bottom: 1px solid var(--line);
        }
        .category-block:last-child { border-bottom: 0; padding-bottom: 0; }
        .category-block:first-of-type { padding-top: .25rem; }
        .category-head {
            display: flex; justify-content: space-between; align-items: center;
            gap: .75rem; flex-wrap: wrap; margin-bottom: .35rem;
        }
        .me-panel {
            border-color: #b9d8cd;
            background:
                linear-gradient(135deg, rgba(216, 239, 231, .55), rgba(255,255,255,.9) 55%);
        }
        .me-panel-head {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;
        }
        .me-net {
            font-family: "Fraunces", Georgia, serif;
            font-size: 1.45rem; font-weight: 700; text-align: right;
        }
        .me-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
        }
        .me-col {
            background: rgba(255,255,255,.72);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1rem;
        }
        .me-total { font-weight: 700; margin: 0 0 .75rem; }
        @media (max-width: 800px) {
            .me-grid { grid-template-columns: 1fr; }
            .me-net { text-align: left; }
        }
        .pie-layout {
            display: grid;
            grid-template-columns: minmax(180px, 220px) 1fr;
            gap: 1.25rem;
            align-items: center;
        }
        .pie-chart {
            width: 100%;
            max-width: 220px;
            aspect-ratio: 1;
            overflow: visible;
        }
        .pie-chart path, .pie-chart circle:not([fill="#fff"]) {
            transition: opacity .2s ease, transform .2s ease;
            transform-origin: 50% 50%;
        }
        .pie-chart path:hover, .pie-chart circle:not([fill="#fff"]):hover {
            opacity: .88;
            transform: scale(1.02);
        }
        .pie-center-label {
            font-size: 4px;
            fill: var(--muted);
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
        }
        .pie-center-value {
            font-size: 5.5px;
            font-weight: 700;
            fill: var(--ink);
            font-family: "Fraunces", Georgia, serif;
        }
        .pie-legend {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: .65rem;
        }
        .pie-legend li {
            display: flex;
            gap: .65rem;
            align-items: flex-start;
        }
        .pie-swatch {
            width: .85rem;
            height: .85rem;
            border-radius: 4px;
            margin-top: .2rem;
            flex-shrink: 0;
        }
        .pie-legend-copy {
            display: grid;
            gap: .1rem;
        }
        @media (max-width: 800px) {
            .pie-layout { grid-template-columns: 1fr; justify-items: center; }
            .pie-legend { width: 100%; }
        }
        .split { display: grid; grid-template-columns: 1.2fr .8fr; gap: 1rem; align-items: start; }
        .hero-home {
            min-height: calc(100vh - 8rem);
            display: grid; align-content: center; gap: 1rem;
            animation: rise .7s ease both;
        }
        .hero-home .brand-mark {
            font-family: "Fraunces", Georgia, serif;
            font-size: clamp(2.4rem, 6vw, 3.6rem);
            line-height: 1.05; color: var(--accent); margin: 0;
            animation: rise .8s .05s ease both;
        }
        .hero-home p { max-width: 36rem; font-size: 1.1rem; animation: rise .8s .12s ease both; }
        .hero-home .cta { display: flex; gap: .75rem; flex-wrap: wrap; animation: rise .8s .2s ease both; }
        @keyframes rise {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }
        @media (max-width: 800px) {
            .grid-2, .grid-3, .split { grid-template-columns: 1fr; }
            .shell { padding: 1rem; }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="shell">
        <nav class="nav">
            <a class="brand" href="{{ auth()->check() ? route('dashboard') : route('home') }}">House Expenses</a>
            <div style="display:flex; gap:1rem; align-items:center;">
                @auth
                    <span class="muted">{{ auth()->user()->name }}</span>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Logout</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Login</a>
                    <a href="{{ route('register') }}">Register</a>
                @endauth
            </div>
        </nav>
        {{ $slot }}
    </div>
    @livewireScripts
</body>
</html>
