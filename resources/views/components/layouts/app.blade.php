<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'House Expenses' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f7fbf2;
            --ink: #26323d;
            --muted: #44546a;
            --accent: #24364b;
            --accent-soft: #dce6f1;
            --line: #d8dee6;
            --danger: #b42318;
            --ok: #2f6b3a;
            --warn: #a16207;
            --surface: #ffffff;
            --mint: #d9ead3;
            --mint-line: #8eba85;
            --mint-ink: #22352a;
            --amber: #fff2cc;
            --amber-line: #d6b656;
            --amber-ink: #4b3c12;
            --bar-1: #7ea6c8;
            --bar-2: #9bc07e;
            --bar-3: #f0b35d;
            --bar-4: #d98c8c;
            --radius: 18px;
            --shadow: 0 18px 36px rgba(28, 43, 57, .12);
        }
        body {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 85% 12%, rgba(217, 234, 211, .75) 0%, transparent 18%),
                radial-gradient(circle at 12% 88%, rgba(220, 230, 241, .85) 0%, transparent 22%),
                linear-gradient(135deg, #eef7ff 0%, #f7fbf2 55%, #fff4e6 100%);
            min-height: 100vh;
        }
        .shell { width: 80%; margin: 0 auto; padding: 1.5rem; }
        .nav {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem;
            padding: .9rem 1.25rem;
            background: var(--accent);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .brand {
            font-family: "Fraunces", Georgia, serif; font-size: 1.25rem; font-weight: 700;
            color: #fff; text-decoration: none; letter-spacing: -.02em;
        }
        .nav a, .nav button {
            color: rgba(255,255,255,.78); text-decoration: none; background: none; border: 0; cursor: pointer; font: inherit;
        }
        .nav a:hover, .nav button:hover { color: #fff; }
        .nav .muted { color: rgba(255,255,255,.65); }
        .panel {
            background: var(--surface);
            border: 1px solid #b7b7b7;
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 8px 24px rgba(28, 43, 57, .06);
        }
        .panel-headed {
            padding: 0;
            overflow: hidden;
        }
        .panel-head {
            background: #44546a;
            color: #fff;
            padding: .9rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .panel-head h2 {
            margin: 0;
            color: #fff;
            font-size: 1.1rem;
        }
        .panel-head .muted { color: rgba(255,255,255,.78); }
        .panel-head .badge { background: rgba(255,255,255,.16); color: #fff; }
        .panel-body { padding: 1.25rem; }
        h1, h2, h3 { font-family: "Fraunces", Georgia, serif; margin: 0 0 .75rem; letter-spacing: -.02em; color: var(--ink); }
        h1 { font-size: 1.85rem; }
        h2 { font-size: 1.25rem; }
        h3 { font-size: 1.05rem; color: var(--muted); font-weight: 600; }
        label { display: block; font-size: .9rem; margin-bottom: .35rem; color: var(--muted); }
        input, select, textarea {
            width: 100%; box-sizing: border-box; border: 1px solid var(--line);
            border-radius: 12px; padding: .65rem .75rem; margin-bottom: .85rem; background: #fff;
            font: inherit; color: var(--ink);
        }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: #7ea6c8; box-shadow: 0 0 0 3px rgba(126, 166, 200, .25);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }
        .btn {
            display: inline-block; background: var(--accent); color: #fff; border: 0;
            border-radius: 12px; padding: .7rem 1rem; cursor: pointer; font-weight: 600; font: inherit;
            text-decoration: none;
        }
        .btn:hover { background: #31485f; }
        .btn-secondary { background: #fff; color: var(--accent); border: 1px solid var(--line); }
        .btn-secondary:hover { border-color: var(--accent); background: #f8fafc; }
        .btn-danger { background: var(--danger); }
        .btn-sm { padding: .4rem .7rem; font-size: .85rem; }
        .muted { color: var(--muted); }
        .error { color: var(--danger); font-size: .85rem; margin: -.5rem 0 .75rem; }
        .success { color: var(--ok); margin-bottom: .75rem; }
        .flash { margin-bottom: .75rem; padding: .65rem .8rem; border-radius: 12px; background: var(--accent-soft); color: var(--accent); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .8rem .4rem; border-bottom: 1px solid var(--line); font-size: .95rem; vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
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
        .badge-draft { background: #fff2cc; color: var(--warn); }
        .badge-confirmed { background: var(--mint); color: var(--mint-ink); }
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
        .overview-board {
            display: grid;
            grid-template-columns: 1.2fr .9fr;
            gap: 1rem;
            align-items: start;
            margin-bottom: 1rem;
        }
        .overview-side {
            display: grid;
            gap: 1rem;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .stat-card {
            border-radius: var(--radius);
            padding: 1.15rem 1.2rem;
            min-height: 7.5rem;
            box-shadow: 0 8px 24px rgba(28, 43, 57, .06);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-card h3 {
            margin: 0;
            font-family: "Source Sans 3", "Segoe UI", sans-serif;
            font-size: 1rem;
            font-weight: 700;
        }
        .stat-card .stat-value {
            font-family: "Fraunces", Georgia, serif;
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .stat-mint {
            background: var(--mint);
            border: 1px solid var(--mint-line);
            color: var(--mint-ink);
        }
        .stat-amber {
            background: var(--amber);
            border: 1px solid var(--amber-line);
            color: var(--amber-ink);
        }
        .me-panel {
            border-color: #b7b7b7;
            background: #f8fafc;
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
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 1rem;
        }
        .me-total { font-weight: 700; margin: 0 0 .75rem; }
        .availability-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .availability-card {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 1rem;
        }
        .availability-card.is-me {
            background: var(--mint);
            border-color: var(--mint-line);
        }
        .availability-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
            margin-bottom: .75rem;
        }
        .availability-dates {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: .5rem;
        }
        .availability-dates li {
            display: flex;
            align-items: center;
            gap: .65rem;
            flex-wrap: wrap;
            font-size: .95rem;
        }
        .pie-layout {
            display: grid;
            grid-template-columns: minmax(150px, 180px) 1fr;
            gap: 1.25rem;
            align-items: center;
        }
        .pie-chart {
            width: 100%;
            max-width: 180px;
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
            gap: .75rem;
        }
        .pie-legend li {
            display: grid;
            gap: .35rem;
        }
        .pie-legend-meta {
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            align-items: baseline;
        }
        .pie-bar-track {
            height: 12px;
            background: #eef2f6;
            border-radius: 999px;
            overflow: hidden;
        }
        .pie-bar {
            display: block;
            height: 100%;
            border-radius: 999px;
            min-width: 8px;
        }
        .pie-swatch {
            width: .85rem;
            height: .85rem;
            border-radius: 4px;
            margin-top: .2rem;
            flex-shrink: 0;
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
        @media (max-width: 960px) {
            .overview-board, .me-grid, .stat-grid, .grid-2, .grid-3, .split, .availability-grid { grid-template-columns: 1fr; }
            .pie-layout { grid-template-columns: 1fr; justify-items: center; }
            .pie-legend { width: 100%; }
            .me-net { text-align: left; }
            .shell { padding: 1rem; }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
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
