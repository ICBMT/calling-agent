@extends('layouts.workspace')
@section('page-title', 'Overview')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">YOUR BUSINESS, IN YOUR ELEMENT.</span>
            <h1>A good place to start<span class="grape-text">.</span></h1>
            <p>Welcome, {{ explode(' ', auth()->user()->name)[0] }}. Let’s make your next hello count.</p>
        </div>
        <form action="{{ route('workspace.overview') }}" method="GET" class="date-filter"><label for="days">Call
                activity</label><select name="days" id="days">
                @foreach ([7, 30, 90] as $period)
                    <option value="{{ $period }}" @selected($period === $days)>Last {{ $period }} days
                    </option>
                @endforeach
            </select>
            <button type="submit" class="icon-button" aria-label="Apply date filter"><x-icon
                    name="arrow-right" /></button>
        </form>
    </div>
    <div class="workspace-welcome">
        <div><span class="eyebrow">SMALL START. BIG POSSIBILITIES.</span>
            <h2>Give your business<br>a little more <em>you.</em></h2>
            <p>Your first great conversation starts with a draft agent.</p>
            @can('manage', $workspace)
                <a href="{{ route('workspace.agents.create') }}" class="button button--lime button--small">Create an
                agent <x-icon name="plus" /></a>@else<a href="{{ route('workspace.agents.index') }}"
                    class="button button--lime button--small">Explore your agents <x-icon /></a>
            @endcan
        </div>
        <div class="welcome-art" aria-hidden="true"><i></i><i></i>
            <div><x-icon name="wave" /></div><span>✳</span>
        </div><span class="welcome-coordinate">V.01 / YOUR VOICE, IN THE MAKING</span>
    </div>
    @php
        $journeyChecks = collect($checks)->keyBy('label');
        $voiceReady = ($journeyChecks['Voice engine']['ok'] ?? false) && ($journeyChecks['Voice worker']['ok'] ?? false);
        $journey = [
            ['title' => 'Create your agent', 'detail' => 'A name, a purpose, a personality — plus realistic call instructions.', 'done' => $metrics['agents'] > 0, 'href' => route('workspace.agents.index'), 'action' => 'Open agents'],
            ['title' => 'Verify a realtime provider', 'detail' => 'Save an OpenAI or Gemini key and pass a genuine Test Connection.', 'done' => (bool) ($journeyChecks['Provider authentication']['ok'] ?? false), 'href' => route('workspace.providers.index'), 'action' => 'Open providers'],
            ['title' => 'Bring the voice engine online', 'detail' => 'LiveKit reachable and the Python voice worker registered.', 'done' => $voiceReady, 'href' => route('workspace.setup'), 'action' => 'Open setup checks'],
            ['title' => 'Run a live browser call', 'detail' => 'Consent, microphone on, and a real two-way conversation in the Playground.', 'done' => false, 'href' => route('workspace.playground'), 'action' => 'Open playground'],
        ];
        $journeyNext = collect($journey)->firstWhere('done', false);
    @endphp
    <section class="workspace-panel journey-panel" aria-label="Guided setup">
        <div class="panel-heading">
            <h2>Your path to a first live conversation</h2>
            <span class="status-tag {{ $journeyNext ? 'status-tag--warn' : 'status-tag--good' }}">
                {{ $journeyNext ? 'In progress' : 'Ready' }}</span>
        </div>
        <ol class="journey-steps">
            @foreach ($journey as $index => $step)
                <li @class(['journey-step', 'is-done' => $step['done'], 'is-next' => $journeyNext === $step])>
                    <span class="journey-index">
                        <x-icon :name="$step['done'] ? 'check' : 'arrow-right'" /></span>
                    <div>
                        <h3>{{ $index + 1 }}. {{ $step['title'] }}</h3>
                        <p>{{ $step['detail'] }}</p>
                        <a href="{{ $step['href'] }}" class="inline-link">{{ $step['action'] }} <x-icon
                                name="arrow-right" /></a>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="journey-honesty"><x-icon name="info" />Every green step was verified for real — a saved key,
            a passing connection test, a registered worker. Nothing here is decorative.</p>
    </section>
    <div class="metric-grid">
        <article class="metric-card"><span>Total calls <x-icon
                    name="phone" /></span><strong>{{ number_format($metrics['calls']) }}</strong><small>In the
                last {{ $days }} days</small></article>
        <article class="metric-card"><span>Draft agents <x-icon
                    name="agents" /></span><strong>{{ number_format($metrics['agents']) }}</strong><small>Ready
                for your ideas</small></article>
        <article class="metric-card"><span>Completed calls <x-icon
                    name="check" /></span><strong>{{ number_format($metrics['completed']) }}</strong><small>{{ number_format($metrics['active']) }}
                calls currently in progress</small></article>
        <article class="metric-card"><span>Average duration <x-icon
                    name="clock" /></span><strong>{{ \App\Support\Duration::format($metrics['average_duration']) }}</strong><small>{{ $metrics['average_duration'] !== null ? 'Completed calls · minutes:seconds' : 'No completed calls in this period' }}</small>
        </article>
    </div>
    <div class="overview-grid">
        <section class="workspace-panel">
            <div class="panel-heading">
                <h2>Your agents <span>{{ $metrics['agents'] }}</span></h2><a
                    href="{{ route('workspace.agents.index') }}">View all <x-icon name="arrow-up-right" /></a>
            </div>
            @forelse($agents as $agent)
                <a class="overview-agent" href="{{ route('workspace.agents.edit', $agent) }}"><span
                        class="agent-list-avatar"><x-icon name="wave" /></span>
                    <div><strong>{{ $agent->name }}</strong><span>{{ ucfirst($agent->purpose) }} ·
                            {{ ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'][$agent->language] ?? $agent->language }}</span>
                    </div><span class="status-tag">Draft</span><x-icon name="chevron-right" />
            </a>@empty<div class="empty-state empty-state--small">
                    <div class="empty-icon"><x-icon name="agents" /></div>
                    <h3>Your next teammate is an idea away.</h3>
                    <p>No agents yet. Start with a name, a purpose,<br>and a little personality.</p>
                    @can('manage', $workspace)
                        <a href="{{ route('workspace.agents.create') }}" class="inline-link">Create your first agent
                            <x-icon name="arrow-right" /></a>
                    @endcan
                </div>
            @endforelse
        </section>
        <section class="workspace-panel">
            <div class="panel-heading">
                <h2>Foundation health</h2><a href="{{ route('workspace.setup') }}">Details <x-icon /></a>
            </div>
            <div class="health-list">
                @foreach ($checks as $check)
                    <div class="health-row"><span
                            @class(['health-dot', 'is-ok' => $check['ok']])></span><span>{{ $check['label'] }}</span><span
                            @class(['health-status', 'is-ok' => $check['ok']])>{{ $check['status'] }}</span></div>
                @endforeach
            </div>
            <p class="health-footnote">Live checks where implemented. No simulated connections.</p>
        </section>
    </div>
    <section class="workspace-panel recent-calls">
        <div class="panel-heading">
            <h2>Recent conversations</h2><a href="{{ route('workspace.calls') }}">Call history <x-icon /></a>
        </div>
        @if ($recentCalls->isEmpty())
            <div class="empty-state empty-state--wide">
                <div class="empty-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                <h3>A clean slate. A world of possibility.</h3>
                <p>Real call records will appear here once the voice and telephony<br>milestones are connected and
                    verified. No made-up activity.</p><a href="{{ route('workspace.setup') }}"
                    class="inline-link">Explore the setup journey <x-icon name="arrow-right" /></a>
            </div>
        @else
            @include('workspace.partials.call-table', ['calls' => $recentCalls])
        @endif
    </section>
@endsection
