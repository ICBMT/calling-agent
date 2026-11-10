@extends('layouts.workspace')
@section('page-title', 'Setup & connections')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">ONE GOOD STEP AT A TIME.</span>
            <h1>From hello<br>to what’s next<span class="grape-text">.</span></h1>
            <p>A clear view of what works today and what we’re building next.</p>
        </div>
    </div>
    <div class="setup-grid">
        <section class="workspace-panel setup-checklist">
            <div class="panel-heading">
                <h2>Your foundation</h2><span class="status-tag status-tag--good">Available now</span>
            </div>
            <div class="setup-step is-done"><span class="setup-check"><x-icon name="check" /></span>
                <div>
                    <h3>Your own workspace</h3>
                    <p>{{ $workspace->name }} is saved. Your account has {{ $workspace->pivot->role }} access.</p>
                </div>
            </div>
            @foreach (array_slice($checks, 0, 2) as $check)
                <div @class(['setup-step', 'is-done' => $check['ok']])><span class="setup-check"><x-icon :name="$check['ok'] ? 'check' : 'info'" /></span>
                    <div>
                        <h3>{{ $check['label'] }}: {{ strtolower($check['status']) }}</h3>
                        <p>{{ $check['detail'] }}</p>
                    </div>
                </div>
            @endforeach
            <div @class(['setup-step', 'is-done' => $hasAgent])>
                <span class="setup-check"><x-icon :name="$hasAgent ? 'check' : 'plus'" /></span>
                <div>
                    <h3>{{ $hasAgent ? 'Your first agent draft is saved' : 'Create your first agent' }}</h3>
                    <p>A purpose, a personality, and instructions. Make the first hello yours.</p><a
                        href="{{ route('workspace.agents.index') }}"
                        class="inline-link">{{ $hasAgent ? 'Explore your agents' : 'Start with a draft' }}<x-icon
                            name="arrow-right" /></a>
                </div>
            </div>
        </section>
        <section class="workspace-panel setup-health">
            <div class="panel-heading">
                <h2>Service verification</h2><x-icon name="info" />
            </div>
            @foreach (array_slice($checks, 2) as $check)
                <div class="setup-pending-check"><span>{{ $check['label'] }}</span><span
                        class="status-tag">{{ $check['status'] }}</span>
                    <p>{{ $check['detail'] }}</p>
                </div>
            @endforeach
            <p class="setup-honesty">“Connected” will always mean a real check passed. An account, a configured
                credential, and an end-to-end voice test are different milestones.</p>
        </section>
    </div>
    <section class="workspace-panel roadmap-panel">
        <div class="panel-heading">
            <h2>The next frequencies</h2><span class="status-tag">Development roadmap</span>
        </div>
        <div class="roadmap-grid">
            <article><span>02 / PROVIDERS</span>
                <h3>Your intelligence.<br>Your choice.</h3>
                <p>Encrypted credentials, independent adapters, capability checks, and genuine connection tests.
                    No API keys in your browser.</p>
                <a href="{{ route('workspace.providers.index') }}" class="inline-link">Set up your providers <x-icon
                        name="arrow-right" /></a>
                <details>
                    <summary>What is needed? <x-icon name="plus" /></summary>
                    <p>A supported provider account and its credentials, entered through the encrypted provider
                        configuration screen. Credentials alone won’t mark an end-to-end voice test as passed.</p>
                </details>
            </article>
            <article><span>03 / REAL VOICE</span>
                <h3>A conversation.<br>Not a recording.</h3>
                <p>A separate LiveKit worker for streaming audio, interruptions, independent call context, and
                    measured response latency.</p><a href="https://docs.livekit.io/agents/" target="_blank"
                    rel="noopener noreferrer">LiveKit documentation <x-icon /></a>
            </article>
            <article><span>04 / TELEPHONY</span>
                <h3>A whole new<br>kind of hello.</h3>
                <p>Verified incoming calls, then consent-aware outgoing calls. Signed webhooks and real call
                    records before campaigns.</p><a href="https://www.twilio.com/docs/voice" target="_blank"
                    rel="noopener noreferrer">Twilio Voice documentation <x-icon /></a>
            </article>
        </div>
    </section>
    <div class="workspace-info-note"><x-icon name="info" />
        <p><strong>Not a production calling release yet.</strong> Provider configuration and real read-only authentication
            checks are available. There is no live AI audio, SIP, or
            telephone connection. Queue heartbeats, Redis, Reverb, knowledge retrieval, campaigns, and billing
            will be introduced with their verified modules.</p>
    </div>
@endsection
