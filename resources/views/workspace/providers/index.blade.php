@extends('layouts.workspace')
@section('page-title', $platformScope ? 'Platform providers' : 'AI providers')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">YOUR INTELLIGENCE. YOUR CHOICE.</span>
            <h1>Make a connection<span class="grape-text">.</span></h1>
            <p>{{ $platformScope ? 'Shared platform credentials. Only explicitly provisioned operators can manage these.' : 'Bring the providers you trust. Keep the keys where they belong.' }}
            </p>
        </div>
        <a href="{{ route($routePrefix . '.compare') }}" class="button button--outline button--small">Compare providers <x-icon
                name="arrow-up-right" /></a>
    </div>
    <x-form-errors />
    @if ($platformScope)
        <div class="workspace-info-note"><x-icon name="shield" />
            <p><strong>Platform-wide scope.</strong> Enabled, verified platform defaults may be used by organizations
                without a local default. Never save a customer-owned key here without authorization. <a
                    href="{{ route('workspace.providers.index') }}" class="inline-link">Return to workspace providers</a></p>
        </div>
    @endif
    <div class="provider-trust-strip">
        <div><x-icon name="shield" /><span><strong>Encrypted. Never displayed.</strong><small>Keys are stored with
                    authenticated encryption.</small></span></div>
        <div><x-icon
                name="building" /><span><strong>{{ $platformScope ? 'Explicit operator access.' : 'Your workspace. Your keys.' }}</strong><small>{{ $platformScope ? 'Separate from ordinary organization roles.' : 'Only owners and admins can change credentials.' }}</small></span>
        </div>
        <div><x-icon name="check" /><span><strong>Real checks. Clear limits.</strong><small>Authentication is not an
                    end-to-end voice test.</small></span></div>
    </div>
    <section aria-labelledby="supported-providers" class="provider-library">
        <div class="provider-section-title">
            <h2 id="supported-providers">Choose your building blocks</h2><span>{{ count($definitions) }} supported providers · independent
                adapters</span>
        </div>
        <div class="provider-library-grid">
            @foreach ($definitions as $key => $definition)
                <article class="provider-library-card">
                    <span class="provider-monogram" aria-hidden="true">{{ $definition['initials'] }}</span>
                    <h3>{{ $definition['name'] }}</h3>
                    <p>{{ $definition['summary'] }}</p>
                    <div class="provider-capabilities">
                        @foreach ($definition['capabilities'] as $value)
                            <span>{{ ['realtime' => 'Realtime', 'stt' => 'STT', 'llm' => 'LLM', 'tts' => 'TTS'][$value] }}</span>
                        @endforeach
                    </div>
                    @if ($canManage)
                        <a href="{{ route($routePrefix . '.create', ['provider' => $key]) }}" class="inline-link">Add
                            {{ $definition['name'] }} <x-icon name="plus" /></a>
                    @else
                        <a href="{{ $definition['docs'] }}" target="_blank" rel="noopener noreferrer"
                            class="inline-link">{{ $definition['name'] }} documentation <x-icon
                                name="arrow-up-right" /></a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
    <section class="workspace-panel provider-configurations" aria-labelledby="saved-providers">
        <div class="panel-heading">
            <h2 id="saved-providers">{{ $platformScope ? 'Platform' : 'Workspace' }} connections
                <span>{{ $configurations->total() }}</span></h2><span class="status-tag">No keys in responses</span>
        </div>
        @forelse ($configurations as $configuration)
            <article class="provider-config-row">
                <span class="provider-monogram provider-monogram--small"
                    aria-hidden="true">{{ $definitions[$configuration->provider]['initials'] }}</span>
                <div class="provider-config-main">
                    <h3><a href="{{ route($routePrefix . '.edit', $configuration->id) }}">{{ $configuration->name }}</a>
                    </h3>
                    <p>{{ $definitions[$configuration->provider]['name'] }} ·
                        {{ \App\Services\Providers\Capability::from($configuration->capability)->label() }}</p>
                    <small>{{ $configuration->selected_model ?? 'No model selected' }}{{ $configuration->selected_voice ? ' · ' . $configuration->selected_voice : '' }}</small>
                </div>
                <div class="provider-config-state"><span
                        @class([
                            'status-tag',
                            'status-tag--good' => $configuration->freshCatalog(),
                        ])>{{ $configuration->statusLabel() }}</span><small>{{ $configuration->enabled ? 'Enabled' : 'Disabled' }}
                        ·
                        {{ $configuration->routing_role === 'none' ? 'Unassigned' : ucfirst($configuration->routing_role) }}</small>
                </div>
                <a href="{{ route($routePrefix . '.edit', $configuration->id) }}"
                    class="provider-edit-link">{{ $canManage ? 'Manage' : 'View' }}<span class="sr-only">
                        {{ $configuration->name }}</span><x-icon name="arrow-right" /></a>
            </article>
        @empty
            <div class="empty-state empty-state--small">
                <div class="empty-icon"><x-icon name="wave" /></div>
                <h3>Good conversations start with a connection.</h3>
                <p>No provider credentials are saved in this
                    scope.<br>{{ $canManage ? 'Choose a provider above to get started.' : 'Ask your workspace owner or admin to add a provider.' }}
                </p>
            </div>
        @endforelse
        @if ($configurations->hasPages())
            <div class="pagination-wrap">{{ $configurations->links() }}</div>
        @endif
    </section>
    <div class="workspace-info-note"><x-icon name="info" />
        <p><strong>A connection, not a call.</strong> Saving a key never marks it connected. Test Connection performs real
            read-only authentication and catalog requests; it does not generate text or audio, verify inference permissions,
            or activate an agent. Local defaults override shared platform defaults. Only an explicitly designated,
            same-capability fallback can be selected by the backend resolver.</p>
    </div>
    @if (auth()->user()->is_platform_admin && !$platformScope)
        <a href="{{ route('workspace.platform.providers.index') }}" class="inline-link">Manage shared platform providers
            <x-icon name="shield" /></a>
    @endif
@endsection
