@extends('layouts.workspace')
@section('page-title', 'Call details')
@section('content')
    <a href="{{ route('workspace.calls') }}" class="workspace-back">
        ← All call history</a>
    <div class="workspace-page-heading">
        <div><span class="eyebrow">CONVERSATION RECORD.</span>
            <h1>Call <code>{{ mb_substr($call->public_id, 0, 8) }}</code><span class="grape-text">.</span></h1>
            <p>Full metadata and lifecycle timestamps for this conversation.</p>
        </div>
        <span class="status-tag status-tag--large">{{ ucfirst(str_replace('_', ' ', $call->status)) }}</span>
    </div>

    <div class="metric-grid">
        <article class="metric-card">
            <span>Direction <x-icon name="phone" /></span>
            <strong>{{ ucfirst($call->direction) }}</strong>
            <small>{{ $call->direction === 'browser' ? 'Browser WebRTC session' : 'Telephony PSTN' }}</small>
        </article>
        <article class="metric-card">
            <span>Duration <x-icon name="clock" /></span>
            <strong>{{ \App\Support\Duration::format($call->duration_seconds) }}</strong>
            <small>{{ $call->duration_seconds !== null ? 'Total connected duration' : 'In progress or incomplete' }}</small>
        </article>
        <article class="metric-card">
            <span>Caller <x-icon name="wave" /></span>
            <strong>{{ $call->caller_number ?? 'Anonymous' }}</strong>
            <small>Originating party</small>
        </article>
        <article class="metric-card">
            <span>Destination <x-icon name="arrow-right" /></span>
            <strong>{{ $call->destination_number ?? 'Direct Room' }}</strong>
            <small>Inbound routing number</small>
        </article>
    </div>

    <div class="overview-grid">
        <section class="workspace-panel">
            <div class="panel-heading">
                <h2>Assigned agent</h2>
                @if($call->agent)
                    <a href="{{ route('workspace.agents.edit', $call->agent) }}">Edit agent <x-icon name="arrow-up-right" /></a>
                @endif
            </div>
            @if ($call->agent)
                <div class="overview-agent">
                    <span class="agent-list-avatar"><x-icon name="wave" /></span>
                    <div>
                        <strong>{{ $call->agent->name }}</strong>
                        <span>{{ ucfirst($call->agent->purpose) }} · {{ ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'][$call->agent->language] ?? $call->agent->language }}</span>
                    </div>
                    <span class="status-tag">Assigned</span>
                </div>
            @else
                <div class="empty-state empty-state--small">
                    <div class="empty-icon"><x-icon name="agents" /></div>
                    <h3>No agent assigned</h3>
                    <p>This call was handled by default workspace routing or generic LiveKit media participant.</p>
                </div>
            @endif
        </section>

        <section class="workspace-panel">
            <div class="panel-heading">
                <h2>Lifecycle timestamps</h2>
            </div>
            <div class="health-list">
                <div class="health-row">
                    <span class="health-dot is-ok"></span>
                    <span>Created</span>
                    <span class="health-status">{{ $call->created_at->timezone($workspace->timezone)->format('M j, Y H:i:s T') }}</span>
                </div>
                @if ($call->started_at)
                    <div class="health-row">
                        <span class="health-dot is-ok"></span>
                        <span>Connected</span>
                        <span class="health-status">{{ $call->started_at->timezone($workspace->timezone)->format('M j, Y H:i:s T') }}</span>
                    </div>
                @endif
                @if ($call->ended_at)
                    <div class="health-row">
                        <span class="health-dot"></span>
                        <span>Terminated</span>
                        <span class="health-status">{{ $call->ended_at->timezone($workspace->timezone)->format('M j, Y H:i:s T') }}</span>
                    </div>
                @endif
                <div class="health-row">
                    <span class="health-dot is-ok"></span>
                    <span>Session UUID</span>
                    <span class="health-status"><code>{{ $call->public_id }}</code></span>
                </div>
            </div>
        </section>
    </div>

    <div class="workspace-info-note">
        <x-icon name="shield" />
        <p><strong>Tenant-isolated call record.</strong> Call identifiers, caller IDs, and telemetry data are encrypted and accessible only to members of this workspace. No audio is retained without explicit consent configuration.</p>
    </div>
@endsection
