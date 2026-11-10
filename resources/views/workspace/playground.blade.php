@extends('layouts.workspace')
@section('page-title', 'Real-Time Browser Voice')
@section('content')
    <div x-data="voicePlayground()" class="voice-playground-root">
        <script type="application/json" data-voice-options>{!! json_encode($voiceOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

        <div class="workspace-page-heading">
            <div>
                <span class="eyebrow">MILESTONE 3 / REAL-TIME VOICE ENGINE</span>
                <h1>Native Realtime Browser Voice<span class="grape-text">.</span></h1>
                <p>Two-way streamed WebRTC conversation with turn detection, natural interruptions, and live ephemeral captions.</p>
            </div>
            <div>
                <span class="status-tag status-tag--large"
                    :class="{
                        'status-tag--good': status === 'active',
                        'status-tag--warn': reconnecting || status === 'connecting' || status === 'requesting_mic',
                        'status-tag--danger': status === 'failed'
                    }"
                    x-text="
                        status === 'active' ? 'Live session' :
                        status === 'connecting' ? 'Connecting to LiveKit...' :
                        status === 'requesting_mic' ? 'Requesting microphone...' :
                        reconnecting ? 'Reconnecting audio...' :
                        status === 'failed' ? 'Session failed' :
                        status === 'ended' ? 'Session ended' :
                        (options?.available ? 'Ready for test' : 'Configuration required')
                    ">
                    {{ $voiceOptions['available'] ? 'Ready for test' : 'Configuration required' }}
                </span>
            </div>
        </div>

        @if (!empty($blockers))
            <div class="workspace-panel voice-blockers-panel" role="alert">
                <div class="panel-heading">
                    <h2><x-icon name="info" /> Setup prerequisites for real-time voice</h2>
                    <span class="status-tag status-tag--warn">Action needed</span>
                </div>
                <p class="voice-blockers-intro">Browser voice requires configured LiveKit infrastructure, a running voice worker, and an enabled native realtime provider default.</p>
                <ul class="voice-blockers-list">
                    @foreach ($blockers as $blocker)
                        <li>
                            <x-icon name="close" />
                            <span>{{ $blocker }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="voice-blockers-actions">
                    <a href="{{ route('workspace.providers.index') }}" class="button button--small button--outline">
                        Configure Providers <x-icon name="arrow-right" />
                    </a>
                    <a href="{{ route('workspace.agents.index') }}" class="button button--small button--outline">
                        Manage Agents <x-icon name="arrow-right" />
                    </a>
                    <a href="{{ route('workspace.setup') }}" class="button button--small button--outline">
                        Check Infrastructure <x-icon name="arrow-right" />
                    </a>
                </div>
            </div>
        @endif

        <template x-if="hasPriorSession">
            <div class="workspace-panel voice-prior-panel" role="alert">
                <div class="panel-heading">
                    <h2><x-icon name="clock" /> Previous session pending cleanup</h2>
                    <span class="status-tag status-tag--warn">Incomplete cleanup</span>
                </div>
                <p>An earlier voice session is still open or awaiting room cleanup. Release it before starting a new conversation.</p>
                <button type="button" class="button button--small button--lime" @click="clearPriorSession()">
                    Release prior session <x-icon name="arrow-right" />
                </button>
            </div>
        </template>

        <div class="playground-grid voice-grid">
            <!-- Left Column: Configuration & Controls -->
            <div class="voice-col-controls">
                <section class="workspace-panel voice-config-panel">
                    <div class="panel-heading">
                        <h2>Session configuration</h2>
                        <span class="status-tag">OpenAI Native Realtime</span>
                    </div>

                    <form @submit.prevent="start()" class="voice-form">
                        <div class="field">
                            <label for="voice-agent-select">Draft agent instructions</label>
                            <select id="voice-agent-select" x-model="agentId" @change="agentChanged()" :disabled="isLive">
                                @forelse ($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }} ({{ strtoupper($agent->language) }})</option>
                                @empty
                                    <option value="" disabled>No draft agents saved yet</option>
                                @endforelse
                            </select>
                            <small class="field-hint">Instructions are used for this session and not logged to disk.</small>
                        </div>

                        <div class="form-row">
                            <div class="field">
                                <label for="voice-model-select">Realtime model</label>
                                <select id="voice-model-select" x-model="model" @change="modelChanged()" :disabled="isLive">
                                    <template x-for="item in (options?.choices?.models || [])" :key="item.id">
                                        <option :value="item.id" x-text="item.name || item.id"></option>
                                    </template>
                                </select>
                            </div>

                            <div class="field">
                                <label for="voice-voice-select">Voice identity</label>
                                <select id="voice-voice-select" x-model="voice" :disabled="isLive">
                                    <template x-for="item in allowedVoices" :key="item.id">
                                        <option :value="item.id" x-text="item.name || item.id"></option>
                                    </template>
                                </select>
                            </div>
                        </div>

                        <div class="field">
                            <label for="voice-language-select">Spoken language</label>
                            <select id="voice-language-select" x-model="language" :disabled="isLive">
                                <template x-for="item in (options?.choices?.languages || [])" :key="item.code">
                                    <option :value="item.code" x-text="item.name"></option>
                                </template>
                            </select>
                            <small class="field-hint">Urdu, English, and Hindi where supported by the realtime model.</small>
                        </div>

                        <div class="voice-consent-box">
                            <label class="voice-checkbox-label">
                                <input type="checkbox" id="voice-consent" x-model="consent" :disabled="isLive" required>
                                <span>I consent to streaming microphone audio directly to OpenAI Realtime for this live test. Audio and ephemeral transcripts are not recorded or persisted to disk.</span>
                            </label>
                        </div>

                        <div class="voice-action-row" x-show="!isLive">
                            <button type="submit" class="button button--lime button--full" :disabled="!canStart">
                                <span x-show="status === 'requesting_mic'">Requesting microphone...</span>
                                <span x-show="status === 'connecting'">Connecting to LiveKit...</span>
                                <span x-show="status !== 'requesting_mic' && status !== 'connecting'">Start voice session</span>
                                <x-icon name="arrow-right" />
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Live Session Control Panel -->
                <section class="workspace-panel voice-session-panel" x-show="isLive || status === 'requesting_mic'" x-cloak>
                    <div class="panel-heading">
                        <h2>Live session controls</h2>
                        <div class="voice-timer" x-show="remainingSeconds !== null">
                            <x-icon name="clock" />
                            <span x-text="formatCountdown()">--:--</span>
                        </div>
                    </div>

                    <div class="voice-state-indicators">
                        <div class="voice-state-chip">
                            <span class="voice-state-label">Agent state</span>
                            <strong class="voice-state-val" x-text="agentState.toUpperCase()">IDLE</strong>
                        </div>
                        <div class="voice-state-chip">
                            <span class="voice-state-label">Microphone</span>
                            <strong class="voice-state-val" :class="{ 'is-muted': muted }" x-text="muted ? 'MUTED' : (userState === 'speaking' ? 'SPEAKING' : 'LISTENING')">LISTENING</strong>
                        </div>
                    </div>

                    <div class="voice-control-actions">
                        <button type="button" class="button button--small" :class="muted ? 'button--lime' : 'button--outline'" @click="toggleMute()">
                            <x-icon name="mic" />
                            <span x-text="muted ? 'Unmute microphone' : 'Mute microphone'">Mute microphone</span>
                        </button>

                        <button type="button" class="button button--small button--outline" :class="{ 'is-interrupted': interrupted }" @click="interrupt()" :disabled="interrupting || status !== 'active'">
                            <x-icon name="close" />
                            <span x-text="interrupting ? 'Interrupting...' : 'Interrupt AI'">Interrupt AI</span>
                        </button>

                        <button type="button" class="button button--small button--outline" x-show="!canPlayback" @click="enableSpeaker()">
                            <x-icon name="headphones" />
                            <span>Enable speaker</span>
                        </button>

                        <button type="button" class="button button--small button--danger" @click="stop()">
                            <x-icon name="close" />
                            <span>End session</span>
                        </button>
                    </div>

                    <div class="voice-warning-banner" x-show="reconnecting" role="alert">
                        <x-icon name="info" />
                        <span>Connection dropped. Reconnecting audio...</span>
                    </div>

                    <div class="voice-error-banner" x-show="error" role="alert">
                        <x-icon name="info" />
                        <span x-text="error"></span>
                    </div>
                </section>

                <div class="voice-error-banner" x-show="!isLive && error" role="alert" x-cloak>
                    <x-icon name="info" />
                    <div>
                        <strong>Session error:</strong>
                        <span x-text="error"></span>
                    </div>
                </div>

                <!-- Local Hardware Mic Check Dropdown -->
                <details class="voice-local-check-details" open>
                    <summary><x-icon name="mic" /> Local microphone device check (sound check only)</summary>
                    <div class="voice-local-check-wrapper">
                        <x-microphone-check />
                    </div>
                </details>
            </div>

            <!-- Right Column: Live Ephemeral Transcript & Measured Diagnostics -->
            <div class="voice-col-stream">
                <!-- Ephemeral Transcript Panel -->
                <section class="workspace-panel voice-transcript-panel">
                    <div class="panel-heading">
                        <h2>Live conversation transcript</h2>
                        <span class="status-tag">In-memory only · Ephemeral</span>
                    </div>
                    <p class="voice-transcript-note">Transcripts stream directly from room events. They are never saved to database or server logs.</p>

                    <div class="voice-transcript-stream" role="log" aria-live="polite">
                        <template x-if="transcript.length === 0 && !userPartial && !assistantPartial">
                            <div class="empty-state empty-state--small">
                                <p>Start a session and speak into your microphone. Live speech and AI responses will stream here in real time.</p>
                            </div>
                        </template>

                        <template x-for="item in transcript" :key="item.id">
                            <div class="voice-turn" :class="'voice-turn--' + item.role">
                                <div class="voice-turn-header">
                                    <strong x-text="item.role === 'user' ? 'You' : 'AI Assistant'"></strong>
                                    <span class="status-tag status-tag--warn" x-show="item.interrupted">Interrupted</span>
                                </div>
                                <p class="voice-turn-text" x-text="item.text"></p>
                            </div>
                        </template>

                        <!-- Live partials -->
                        <template x-if="userPartial?.text">
                            <div class="voice-turn voice-turn--user voice-turn--partial">
                                <div class="voice-turn-header">
                                    <strong>You (listening...)</strong>
                                </div>
                                <p class="voice-turn-text" x-text="userPartial.text"></p>
                            </div>
                        </template>

                        <template x-if="assistantPartial?.text">
                            <div class="voice-turn voice-turn--assistant voice-turn--partial">
                                <div class="voice-turn-header">
                                    <strong>AI Assistant (speaking...)</strong>
                                </div>
                                <p class="voice-turn-text" x-text="assistantPartial.text"></p>
                            </div>
                        </template>
                    </div>
                </section>

                <!-- Measured Performance Diagnostics Panel -->
                <section class="workspace-panel voice-metrics-panel">
                    <div class="panel-heading">
                        <h2>Measured performance diagnostics</h2>
                        <span class="status-tag">Actual telemetry only</span>
                    </div>
                    <p class="voice-metrics-note">Derived strictly from browser WebRTC stats and worker telemetry. Missing measurements display “Not measured”, never zero or an invented SLA.</p>

                    <div class="voice-metrics-groups">
                        <!-- Browser WebRTC RTCStats -->
                        <div class="voice-metric-group">
                            <h3>Browser WebRTC Audio Metrics <small>(Local browser RTCStats)</small></h3>
                            <div class="voice-metrics-grid">
                                <div class="voice-metric-card">
                                    <span>Round-trip time (RTT)</span>
                                    <strong x-text="formatMs(rtcMetrics.rtt_ms)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Inbound audio jitter</span>
                                    <strong x-text="formatMs(rtcMetrics.jitter_ms)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Packets lost</span>
                                    <strong x-text="rtcMetrics.packets_lost ?? 'Not measured'">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Jitter buffer delay</span>
                                    <strong x-text="formatMs(rtcMetrics.mean_buffer_ms)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Uplink bitrate</span>
                                    <strong x-text="formatKbps(rtcMetrics.uplink_kbps)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Capture sample rate</span>
                                    <strong x-text="formatHz(rtcMetrics.capture_hz)">Not measured</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Worker Telemetry Metrics -->
                        <div class="voice-metric-group">
                            <h3>Worker & Provider Telemetry <small>(Separate LiveKit Python worker)</small></h3>
                            <div class="voice-metrics-grid">
                                <div class="voice-metric-card">
                                    <span>Provider first audio</span>
                                    <strong x-text="formatMs(workerMetrics.provider_first_audio_ms)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Worker turn time</span>
                                    <strong x-text="formatMs(workerMetrics.worker_turn_ms)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Model turns</span>
                                    <strong x-text="workerMetrics.turns ?? 'Not measured'">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Barge-in interruptions</span>
                                    <strong x-text="workerMetrics.interruptions ?? 'Not measured'">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Audio tokens (in / out)</span>
                                    <strong x-text="formatTokens(workerMetrics.input_audio_tokens, workerMetrics.output_audio_tokens)">Not measured</strong>
                                </div>
                                <div class="voice-metric-card">
                                    <span>Text tokens (in / out)</span>
                                    <strong x-text="formatTokens(workerMetrics.input_tokens, workerMetrics.output_tokens)">Not measured</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Hidden container for LiveKit remote audio track element -->
        <div x-ref="audioContainer" class="voice-audio-container" aria-hidden="true" style="display:none;"></div>
    </div>
@endsection
