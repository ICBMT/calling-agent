@props(['name' => 'arrow-up-right'])
@php
    $paths = [
        'arrow-up-right' => '<path d="M7 17 17 7M7 7h10v10"/>',
        'arrow-right' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'arrow-down' => '<path d="M12 4v16m-6-6 6 6 6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'menu' => '<path d="M4 8h16M4 16h16"/>',
        'play' => '<path d="m9 5 11 7-11 7z"/>',
        'pause' => '<path d="M9 5v14M15 5v14"/>',
        'mic' =>
            '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2m-7 9v3m-4 0h8"/>',
        'headphones' =>
            '<path d="M3 14v-3a9 9 0 0 1 18 0v3"/><rect x="3" y="12" width="4" height="9" rx="2"/><rect x="17" y="12" width="4" height="9" rx="2"/>',
        'phone' =>
            '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z"/>',
        'sparkles' =>
            '<path d="m12 3 2.3 6.7L21 12l-6.7 2.3L12 21l-2.3-6.7L3 12l6.7-2.3L12 3zm7 0v4m-2-2h4"/>',
        'shield' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m8 12 3 3 5-6"/>',
        'globe' =>
            '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'wave' => '<path d="M3 10v4m4-8v12m5-16v20m5-16v12m4-8v4"/>',
        'grid' =>
            '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'agents' =>
            '<circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2M19 4a4 4 0 0 1 0 8m3 9v-2a7 7 0 0 0-3-6"/>',
        'settings' =>
            '<path d="m9 3-1 3-3 1 1 3-2 2 2 2-1 3 3 1 1 3h6l1-3 3-1-1-3 2-2-2-2 1-3-3-1-1-3z"/><circle cx="12" cy="12" r="3"/>',
        'book' =>
            '<path d="M4 3h6a3 3 0 0 1 3 3v15a4 4 0 0 0-4-2H3V3h1zm9 3a3 3 0 0 1 3-3h5v16h-5a4 4 0 0 0-3 2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m-7 5h12"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4"/>',
        'building' =>
            '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h1m4 0h1M9 11h1m4 0h1m-4 10v-5h2v5"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3m-10 0 1 15h12l1-15M10 10v7m4-7v7"/>',
        'edit' => '<path d="m16 3 5 5L9 20l-6 1 1-6L16 3zm-3 3 5 5"/>',
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} width="24" height="24" viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true">{!! $paths[$name] ?? $paths['arrow-up-right'] !!}</svg>
