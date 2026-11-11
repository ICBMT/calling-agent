@extends('layouts.base')
@section('title')
    @yield('page-title', 'Workspace') — Voca
@endsection
@section('body-class', 'workspace-page')
@section('body')
    <div class="workspace-shell" x-data="workspaceNavigation" @keydown.escape.window="close()">
        <button type="button" class="sidebar-backdrop" x-show="sidebar" @click="close()" x-cloak tabindex="-1"
            aria-label="Close workspace navigation"></button>
        <aside class="workspace-sidebar" :class="{ 'is-open': sidebar }" id="workspace-navigation"
            :inert="mobile && !sidebar" :aria-hidden="mobile && !sidebar"
            :role="mobile && sidebar ? 'dialog' : 'complementary'" :aria-modal="mobile && sidebar ? 'true' : null"
            aria-label="Workspace navigation" @keydown.tab="trapFocus($event)">
            <div class="sidebar-logo-row"><a class="sidebar-logo" href="{{ route('home') }}"
                    aria-label="Voca home"><x-logo /></a><button type="button" class="workspace-nav-close icon-button"
                    @click="close()" aria-label="Close workspace navigation"><x-icon name="close" /></button></div>
            <div class="workspace-identity"><span class="workspace-avatar"><x-icon name="building" /></span>
                <div><strong>{{ $workspace->name }}</strong><span>{{ ucfirst($workspace->pivot->role) }}
                        workspace</span></div>
            </div>
            <span class="nav-group-label">YOUR WORKSPACE</span>
            <nav aria-label="Workspace navigation">
                @foreach ([['route' => 'workspace.overview', 'label' => 'Overview', 'icon' => 'grid', 'match' => 'workspace.overview'], ['route' => 'workspace.agents.index', 'label' => 'AI agents', 'icon' => 'agents', 'match' => 'workspace.agents.*'], ['route' => 'workspace.playground', 'label' => 'Voice playground', 'icon' => 'wave', 'match' => 'workspace.playground'], ['route' => 'workspace.calls', 'label' => 'Call history', 'icon' => 'phone', 'match' => 'workspace.calls']] as $item)
                    <a href="{{ route($item['route']) }}" @class([
                        'sidebar-link',
                        'is-active' => request()->routeIs($item['match']),
                    ])
                        @if (request()->routeIs($item['match'])) aria-current="page" @endif><x-icon
                            :name="$item['icon']" /><span>{{ $item['label'] }}</span>
                        @if ($item['route'] === 'workspace.playground')
                            <span class="nav-mini-dot"></span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <span class="nav-group-label second-group">MAKE IT YOURS</span>
            <nav aria-label="Workspace settings navigation"><a href="{{ route('workspace.providers.index') }}"
                    @class([
                        'sidebar-link',
                        'is-active' => request()->routeIs(
                            'workspace.providers.*',
                            'workspace.platform.providers.*'),
                    ])><x-icon name="shield" /><span>AI providers</span></a><a
                    href="{{ route('workspace.setup') }}" @class([
                        'sidebar-link',
                        'is-active' => request()->routeIs('workspace.setup'),
                    ])><x-icon name="book" /><span>Setup
                        & connections</span></a><a href="{{ route('workspace.settings') }}"
                    @class([
                        'sidebar-link',
                        'is-active' => request()->routeIs('workspace.settings'),
                    ])><x-icon name="settings" /><span>Settings</span></a></nav>
            <div class="sidebar-bottom">
                <div class="foundation-note"><span>✳</span><strong>Big things start small.</strong>
                    <p>Connect your providers securely.<br>Build your agents. Find your voice.</p><a
                        href="{{ route('workspace.setup') }}">See what’s next <x-icon name="arrow-right" /></a>
                </div>
                <div class="sidebar-user"><span
                        class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <div><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="icon-button" type="submit"
                            aria-label="Log out"><x-icon name="logout" /></button></form>
                </div>
            </div>
        </aside>
        <div class="workspace-main" :inert="mobile && sidebar">
            <header class="workspace-topbar">
                <div><button type="button" class="workspace-menu icon-button" x-ref="toggle" @click="toggle()"
                        :aria-expanded="sidebar" aria-controls="workspace-navigation"
                        aria-label="Toggle workspace navigation"><x-icon name="menu" /></button><span
                        class="topbar-breadcrumb">Your workspace <span>/</span>
                        <strong>@yield('page-title', 'Overview')</strong></span></div><a href="{{ route('workspace.setup') }}"
                    class="foundation-badge"><span></span> Foundation release <x-icon name="arrow-up-right" /></a>
            </header>
            <main id="main-content" class="workspace-content">
                @if (session('status'))
                    <div @class([
                        'flash-status',
                        'flash-status--error' => session('status_type') === 'error',
                    ]) role="{{ session('status_type') === 'error' ? 'alert' : 'status' }}">
                        <x-icon :name="session('status_type') === 'error' ? 'info' : 'check'" /><span>{{ session('status') }}</span><button type="button"
                            class="icon-button" x-data @click="$el.parentElement.remove()"
                            aria-label="Dismiss notification"><x-icon name="close" /></button></div>
                @endif
                @yield('content')
            </main>
            <footer class="workspace-footer"><span>A LITTLE INTELLIGENCE. A LOT OF POSSIBILITY.</span><a
                    href="{{ route('privacy') }}">Privacy & your data <x-icon name="arrow-up-right" /></a>
            </footer>
        </div>
    </div>
@endsection
