@extends('layouts.workspace')
@section('page-title', 'Compare AI providers')
@section('content')
    <a href="{{ route($routePrefix . '.index') }}" class="workspace-back">
        ← Back to providers</a>
    <div class="workspace-page-heading">
        <div><span class="eyebrow">DIFFERENT STRENGTHS. ONE INDEPENDENT PLATFORM.</span>
            <h1>Find your frequency<span class="grape-text">.</span></h1>
            <p>Start with what your conversation needs. Choose providers without coupling your agent to one vendor.</p>
        </div><span class="status-tag">Reviewed {{ config('providers.reviewed_on') }}</span>
    </div>
    <div class="workspace-info-note"><x-icon name="info" />
        <p><strong>No guaranteed prices or latency.</strong> Numeric pricing estimates are not configured for this release.
            Plans, free-tier eligibility, geography, and models change; use each provider’s current pricing page. Comparison
            text and its review date are maintained in the provider registry configuration. No live audio benchmark has been
            run here.</p>
    </div>
    <div class="provider-comparison-grid">
        @foreach ($definitions as $key => $definition)
            <article class="workspace-panel provider-comparison-card">
                <div class="provider-comparison-title"><span class="provider-monogram"
                        aria-hidden="true">{{ $definition['initials'] }}</span>
                    <h2>{{ $definition['name'] }}</h2>
                </div>
                <dl>
                    @foreach ($definition['comparison'] as $label => $value)
                        <div>
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="provider-comparison-links"><a href="{{ $definition['pricing_url'] }}" target="_blank"
                        rel="noopener noreferrer" class="inline-link">Current pricing <x-icon name="arrow-up-right" /></a><a
                        href="{{ $definition['docs'] }}" target="_blank" rel="noopener noreferrer"
                        class="inline-link">Documentation <x-icon name="book" /></a>
                    @if ($canManage)
                        <a href="{{ route($routePrefix . '.create', ['provider' => $key]) }}"
                            class="button button--purple button--small">Set up {{ $definition['name'] }} <x-icon
                                name="plus" /></a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <div class="workspace-info-note"><x-icon name="wave" />
        <p><strong>Two architectures, never confused.</strong> Native realtime sends audio directly to an audio-capable
            conversation model. A modular pipeline chooses STT, LLM, and TTS independently. SIP gateways, browser media,
            live interruption handling, audio formats, language compatibility, and end-to-end testing belong to the separate
            voice-engine milestone. Anthropic, local Ollama/Piper/Whisper, custom endpoints, and telephony are not supported
            adapters yet.</p>
    </div>
@endsection
