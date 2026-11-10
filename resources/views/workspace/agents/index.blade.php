@extends('layouts.workspace')
@section('page-title', 'AI agents')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">YOUR NEXT GREAT TEAMMATE.</span>
            <h1>A little personality.<br>A lot of possibility<span class="grape-text">.</span></h1>
            <p>Build the voice that feels like your business.</p>
        </div>
        @can('manage', $workspace)
            <a href="{{ route('workspace.agents.create') }}" class="button button--purple button--small">Create an agent
                <x-icon name="plus" /></a>
        @endcan
    </div>
    <div class="workspace-info-note"><x-icon name="info" />
        <p><strong>Drafts today. Real conversations next.</strong> Agents stay in draft until providers, a voice
            engine, and compatibility checks are available.</p>
    </div>
    <form method="GET" action="{{ route('workspace.agents.index') }}" class="agent-search"><x-icon
            name="search" /><label for="agent-search" class="sr-only">Search agents by name</label><input
            type="search" id="agent-search" name="q" value="{{ $search }}"
            placeholder="Find your agent…" maxlength="100"><button type="submit">Search <x-icon
                name="arrow-right" /></button>
        @if ($search)
            <a href="{{ route('workspace.agents.index') }}">Clear</a>
        @endif
    </form>
    @if ($agents->isEmpty())
        <section class="workspace-panel">
            <div class="empty-state">
                <div class="empty-icon"><x-icon name="agents" /></div>
                <h2>{{ $search ? 'No agents on that frequency.' : 'Say hello to a fresh start.' }}</h2>
                <p>{{ $search ? 'Try another name, or clear your search to see every agent.' : 'The front desk. The support line. The first hello. Give your next agent a name and make it yours.' }}
                </p>
                @can('manage', $workspace)
                    <a href="{{ route('workspace.agents.create') }}"
                        class="button button--purple button--small">Create your first agent <x-icon
                            name="plus" /></a>
                @endcan
            </div>
    </section>@else<div class="agent-grid">
            @foreach ($agents as $agent)
                <article class="agent-card">
                    <div class="agent-card-top"><span class="agent-card-avatar"><x-icon
                                name="wave" /></span><span class="status-tag">Draft</span></div>
                    <h2>{{ $agent->name }}</h2>
                    <p>{{ $agent->description ?: 'A new voice for your business. Ready for your ideas.' }}</p>
                    <div class="agent-card-chips">
                        <span>{{ ucfirst($agent->purpose) }}</span><span>{{ ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'][$agent->language] ?? $agent->language }}</span>
                    </div>
                    <div class="agent-card-bottom"><span>Updated
                            {{ $agent->updated_at->diffForHumans() }}</span><a
                            href="{{ route('workspace.agents.edit', $agent) }}">
                            @can('update', $agent)
                                Edit agent
                            @else
                                View agent
                            @endcan
                            <x-icon /></a></div>
                </article>
            @endforeach
        </div>
        <div class="pagination-wrap">{{ $agents->links() }}</div>
    @endif
@endsection
