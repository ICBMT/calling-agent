import {
    boundedTranscript,
    errorMessage,
    formatMilliseconds,
    isTerminal,
    microphoneCode,
    numericMetrics,
    parsePacket,
} from './protocol.js';

let transportModulePromise = null;
function getTransportModule() {
    if (!transportModulePromise) {
        transportModulePromise = import('./livekit-transport.js');
    }
    return transportModulePromise;
}

export function voicePlayground(initialOptions = null) {
    return {
        options: null,
        provider: '',
        agentId: '',
        model: '',
        voice: '',
        language: '',
        consent: false,
        status: 'idle', // idle, requesting_mic, connecting, active, reconnecting, stopping, ended, failed
        agentState: 'idle',
        userState: 'idle',
        error: null,
        errorCode: null,
        warning: null,
        muted: false,
        canPlayback: true,
        sessionId: null,
        expiresAt: null,
        remainingSeconds: null,
        reconnecting: false,
        interrupted: false,
        interrupting: false,
        hasPriorSession: false,
        priorSessionId: null,

        // Ephemeral transcripts (never stored in DB, localStorage, or sent back to server)
        transcript: [],
        userPartial: null,
        assistantPartial: null,

        // Real-time measurements
        workerMetrics: {
            provider_first_audio_ms: null,
            worker_turn_ms: null,
            input_tokens: null,
            output_tokens: null,
            input_audio_tokens: null,
            output_audio_tokens: null,
            turns: null,
            interruptions: null,
        },
        rtcMetrics: {
            rtt_ms: null,
            jitter_ms: null,
            packets_lost: null,
            mean_buffer_ms: null,
            uplink_kbps: null,
            capture_hz: null,
            connection_quality: null,
        },

        // Generation & timer guards
        generation: 0,
        transport: null,
        pollTimer: null,
        perfTimer: null,
        countdownTimer: null,

        init() {
            let config = initialOptions;
            if (!config) {
                try {
                    const tag = this.$el.querySelector('[data-voice-options]');
                    config = tag ? JSON.parse(tag.textContent) : {};
                } catch {
                    config = {};
                }
            }
            this.options = config || {};
            this.applyInitialConfig();
            this.bindPageLifecycle();
            getTransportModule().catch(() => {});
        },

        applyInitialConfig() {
            const choices = this.options.choices;
            const providers = this.availableProviders;
            if (providers.length > 0) {
                this.provider = choices?.provider_id || providers[0].id;
            }
            const cfg = this.currentProviderConfig;
            const agents = this.options.agents || [];
            if (agents.length > 0) {
                this.agentId = String(agents[0].id);
                if (agents[0].language) {
                    this.language = agents[0].language;
                }
            }
            if (cfg?.default) {
                this.model = cfg.default.model || '';
                this.voice = cfg.default.voice || '';
                if (!this.language && cfg.default.language) {
                    this.language = cfg.default.language;
                }
            } else if (cfg?.models?.length > 0) {
                this.model = cfg.models[0].id;
                this.voice = cfg.models[0].voice_ids?.[0] || cfg.voices?.[0]?.id || '';
                if (!this.language) {
                    this.language = this.currentLanguages[0]?.code || 'en';
                }
            }

            if (this.options.active?.id && !this.options.active.ended_at) {
                this.hasPriorSession = true;
                this.priorSessionId = this.options.active.id;
            }
        },

        bindPageLifecycle() {
            if (typeof window === 'undefined') return;
            window.addEventListener('pagehide', () => {
                if (this.sessionId && this.transport) {
                    this.transport.close();
                    this.destroySession(this.sessionId);
                }
            });
        },

        get availableProviders() {
            const providers = this.options?.choices?.providers;
            if (Array.isArray(providers) && providers.length > 0) {
                return providers;
            }
            if (this.options?.choices?.provider_id) {
                return [
                    {
                        id: this.options.choices.provider_id,
                        name: this.options.choices.provider || this.options.choices.provider_id,
                        models: this.options.choices.models || [],
                        voices: this.options.choices.voices || [],
                        languages: this.options.choices.languages || [],
                        default: this.options.choices.default,
                    },
                ];
            }
            return [];
        },

        get currentProviderConfig() {
            const providers = this.availableProviders;
            return (
                providers.find((p) => p.id === this.provider) ||
                providers[0] ||
                this.options?.choices ||
                null
            );
        },

        get currentProviderName() {
            return (
                this.currentProviderConfig?.name ||
                this.currentProviderConfig?.provider ||
                this.options?.choices?.provider ||
                'Realtime'
            );
        },

        get currentModels() {
            return this.currentProviderConfig?.models || this.options?.choices?.models || [];
        },

        get currentLanguages() {
            const langs = this.currentProviderConfig?.languages || this.options?.choices?.languages;
            if (Array.isArray(langs)) return langs;
            if (langs && typeof langs === 'object') {
                return Object.entries(langs).map(([code, name]) => ({ code, name }));
            }
            return [
                { code: 'en', name: 'English' },
                { code: 'ur', name: 'Urdu' },
                { code: 'hi', name: 'Hindi' },
            ];
        },

        get canStart() {
            return (
                this.options?.available &&
                Boolean(this.agentId) &&
                Boolean(this.model) &&
                Boolean(this.voice) &&
                Boolean(this.language) &&
                this.consent &&
                ['idle', 'ended', 'failed'].includes(this.status) &&
                !this.hasPriorSession
            );
        },

        get isLive() {
            return ['connecting', 'active', 'reconnecting'].includes(this.status);
        },

        get allowedVoices() {
            const cfg = this.currentProviderConfig || this.options?.choices;
            if (cfg?.voices && Array.isArray(cfg.voices) && cfg.voices.length > 0) {
                const modelConfig = cfg.models?.find((m) => m.id === this.model);
                const voiceIds = modelConfig?.voice_ids || modelConfig?.voices;
                if (!voiceIds) return cfg.voices;
                return cfg.voices.filter((v) => voiceIds.includes(v.id));
            }
            const modelConfig = cfg?.models?.find((m) => m.id === this.model);
            if (modelConfig?.voices?.length > 0) {
                return modelConfig.voices.map((v) =>
                    typeof v === 'string' ? { id: v, name: v } : v,
                );
            }
            return [];
        },

        get otherProviders() {
            return this.options?.choices?.other_providers || [];
        },

        providerChanged() {
            const cfg = this.currentProviderConfig;
            if (!cfg) return;
            if (cfg.default?.model) {
                this.model = cfg.default.model;
                this.voice = cfg.default.voice || '';
            } else if (cfg.models?.length > 0) {
                this.model = cfg.models[0].id;
                const allowed = this.allowedVoices;
                this.voice = allowed[0]?.id || '';
            }
            const supportedLangs = this.currentLanguages;
            if (!supportedLangs.some((l) => l.code === this.language)) {
                this.language = supportedLangs[0]?.code || 'en';
            }
        },

        modelChanged() {
            const allowed = this.allowedVoices;
            if (!allowed.some((v) => v.id === this.voice)) {
                this.voice = allowed[0]?.id || '';
            }
        },

        agentChanged() {
            const agent = this.options?.agents?.find((a) => String(a.id) === String(this.agentId));
            if (agent?.language) {
                const supported = this.currentLanguages.some((l) => l.code === agent.language);
                if (supported) {
                    this.language = agent.language;
                }
            }
        },

        async clearPriorSession() {
            if (!this.priorSessionId) return;
            const id = this.priorSessionId;
            this.priorSessionId = null;
            this.hasPriorSession = false;
            await this.destroySession(id);
        },

        getAudioContainer() {
            let container = this.$refs?.audioContainer;
            if (!container) {
                container = document.createElement('div');
                container.className = 'voice-audio-container';
                container.setAttribute('aria-hidden', 'true');
                document.body.appendChild(container);
            }
            return container;
        },

        resetTranscriptAndMetrics() {
            this.transcript = [];
            this.userPartial = null;
            this.assistantPartial = null;
            this.agentState = 'idle';
            this.userState = 'idle';
            this.workerMetrics = {
                provider_first_audio_ms: null,
                worker_turn_ms: null,
                input_tokens: null,
                output_tokens: null,
                input_audio_tokens: null,
                output_audio_tokens: null,
                turns: null,
                interruptions: null,
            };
            this.rtcMetrics = {
                rtt_ms: null,
                jitter_ms: null,
                packets_lost: null,
                mean_buffer_ms: null,
                uplink_kbps: null,
                capture_hz: null,
                connection_quality: null,
            };
        },

        stopTimers() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
            if (this.perfTimer) {
                clearInterval(this.perfTimer);
                this.perfTimer = null;
            }
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        },

        startCountdown() {
            if (this.countdownTimer) clearInterval(this.countdownTimer);
            const update = () => {
                if (!this.expiresAt) {
                    this.remainingSeconds = null;
                    return;
                }
                const diff = Math.max(
                    0,
                    Math.floor((new Date(this.expiresAt).getTime() - Date.now()) / 1000),
                );
                this.remainingSeconds = diff;
                if (diff <= 0 && this.status === 'active') {
                    this.handleTerminal('session_expired');
                }
            };
            update();
            this.countdownTimer = setInterval(update, 1000);
        },

        async start() {
            if (!this.canStart) return;
            this.generation++;
            const currentGen = this.generation;
            this.error = null;
            this.errorCode = null;
            this.warning = null;
            this.status = 'requesting_mic';

            let mic;
            let VoiceTransport;
            try {
                const transportModule = await getTransportModule();
                VoiceTransport = transportModule.VoiceTransport;
                mic = await transportModule.acquireMicrophone();
            } catch (err) {
                if (this.generation !== currentGen) return;
                this.status = 'failed';
                this.errorCode = microphoneCode(err);
                this.error = errorMessage(this.errorCode);
                return;
            }
            if (this.generation !== currentGen) {
                try {
                    mic?.stop?.();
                } catch {}
                return;
            }

            this.status = 'connecting';
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            let data;
            try {
                const response = await fetch('/workspace/voice-sessions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        agent_id: Number(this.agentId),
                        architecture: 'native_realtime',
                        provider: this.provider || undefined,
                        model: this.model,
                        voice: this.voice,
                        language: this.language,
                        consent: true,
                    }),
                });
                if (!response.ok) {
                    const errData = await response.json().catch(() => ({}));
                    throw {
                        status: response.status,
                        code: errData.reason || errData.error_code || 'invalid_request',
                        message: errData.message,
                    };
                }
                data = await response.json();
            } catch (err) {
                try {
                    mic?.stop?.();
                } catch {}
                if (this.generation !== currentGen) return;
                this.status = 'failed';
                this.errorCode = err.code || 'connection_error';
                this.error = err.message || errorMessage(this.errorCode, err.status);
                return;
            }

            if (this.generation !== currentGen) {
                try {
                    mic?.stop?.();
                } catch {}
                this.destroySession(data.session.id);
                return;
            }

            this.sessionId = data.session.id;
            this.expiresAt = data.session.expires_at;
            this.resetTranscriptAndMetrics();
            this.startCountdown();

            const container = this.getAudioContainer();

            try {
                this.transport = new VoiceTransport(
                    data.connection,
                    this.sessionId,
                    mic,
                    container,
                    {
                        audioAttached: () => {
                            if (this.generation !== currentGen) return;
                            this.status = 'active';
                        },
                        failed: (code) => {
                            if (this.generation !== currentGen) return;
                            this.handleTerminal(code);
                        },
                        packet: (bytes) => {
                            if (this.generation !== currentGen) return;
                            this.handlePacket(bytes);
                        },
                        reconnecting: () => {
                            if (this.generation !== currentGen) return;
                            this.reconnecting = true;
                            this.warning = 'Connection dropped. Reconnecting to room...';
                        },
                        reconnected: () => {
                            if (this.generation !== currentGen) return;
                            this.reconnecting = false;
                            this.warning = null;
                        },
                        playback: (canPlay) => {
                            if (this.generation !== currentGen) return;
                            this.canPlayback = canPlay;
                        },
                        quality: (quality) => {
                            if (this.generation !== currentGen) return;
                            this.rtcMetrics.connection_quality = quality;
                        },
                    },
                );

                await this.transport.connect();
            } catch (err) {
                if (this.generation !== currentGen) return;
                this.handleTerminal('connection_error');
                return;
            }

            if (this.generation !== currentGen) return;
            this.status = 'active';
            this.startPolling(currentGen);
            this.startPerformanceMonitoring(currentGen);
        },

        startPolling(currentGen) {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(async () => {
                if (this.generation !== currentGen || !this.sessionId) return;
                try {
                    const res = await fetch(`/workspace/voice-sessions/${this.sessionId}`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (this.generation !== currentGen) return;
                    if (!res.ok) {
                        if (res.status === 404 || res.status === 410) {
                            this.handleTerminal('session_ended');
                        }
                        return;
                    }
                    const data = await res.json();
                    if (this.generation !== currentGen) return;
                    const session = data?.session;
                    if (!session) return;
                    if (session.state === 'active' && this.status === 'connecting') {
                        this.status = 'active';
                    }
                    if (isTerminal(session.state)) {
                        this.handleTerminal(session.error_code || 'session_ended');
                    }
                } catch {
                    // Non-fatal polling network blip
                }
            }, 3000);
        },

        startPerformanceMonitoring(currentGen) {
            if (this.perfTimer) clearInterval(this.perfTimer);
            this.perfTimer = setInterval(async () => {
                if (this.generation !== currentGen || !this.transport || this.status !== 'active')
                    return;
                try {
                    const stats = await this.transport.performance();
                    if (this.generation !== currentGen) return;
                    Object.assign(this.rtcMetrics, stats);
                } catch {
                    // Ignore stats sampling glitches
                }
            }, 2000);
        },

        handlePacket(bytes) {
            const packet = parsePacket(bytes, this.sessionId);
            if (!packet) return;

            switch (packet.type) {
                case 'agent_state':
                    this.agentState = packet.state;
                    break;
                case 'user_state':
                    this.userState = packet.state;
                    break;
                case 'user_partial':
                    if (packet.final) {
                        this.userPartial = null;
                    } else {
                        this.userPartial = { id: packet.id, text: packet.text };
                    }
                    break;
                case 'assistant_partial':
                    if (packet.final) {
                        this.assistantPartial = null;
                    } else {
                        this.assistantPartial = { id: packet.id, text: packet.text };
                    }
                    break;
                case 'transcript':
                    this.transcript = boundedTranscript(this.transcript, packet);
                    if (packet.role === 'assistant' && this.assistantPartial?.id === packet.id) {
                        this.assistantPartial = null;
                    }
                    if (packet.role === 'user' && this.userPartial?.id === packet.id) {
                        this.userPartial = null;
                    }
                    break;
                case 'interrupted':
                    this.interrupted = true;
                    setTimeout(() => {
                        this.interrupted = false;
                    }, 800);
                    break;
                case 'metrics':
                    Object.assign(this.workerMetrics, numericMetrics(packet.values));
                    break;
                case 'error':
                    this.handleTerminal(packet.code);
                    break;
            }
        },

        async interrupt() {
            if (!this.transport || this.status !== 'active' || this.interrupting) return;
            this.interrupting = true;
            try {
                await this.transport.interrupt();
                this.interrupted = true;
                setTimeout(() => {
                    this.interrupted = false;
                }, 800);
            } catch {
                this.handleTerminal('interrupted_failed');
            } finally {
                this.interrupting = false;
            }
        },

        async toggleMute() {
            if (!this.transport) return;
            this.muted = !this.muted;
            try {
                await this.transport.mute(this.muted);
            } catch {
                this.muted = !this.muted;
            }
        },

        async enableSpeaker() {
            if (!this.transport) return;
            try {
                await this.transport.enableSpeaker();
            } catch {}
        },

        stop() {
            this.handleTerminal(null);
        },

        handleTerminal(code) {
            this.generation++;
            this.stopTimers();
            if (this.transport) {
                this.transport.close();
                this.transport = null;
            }
            const id = this.sessionId;
            this.sessionId = null;
            this.status = code ? 'failed' : 'ended';
            this.errorCode = code;
            this.error = code ? errorMessage(code) : null;
            this.warning = null;
            this.reconnecting = false;
            this.muted = false;
            this.userPartial = null;
            this.assistantPartial = null;
            if (id) {
                this.destroySession(id);
            }
        },

        async destroySession(id) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            try {
                await fetch(`/workspace/voice-sessions/${id}`, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    keepalive: true,
                });
            } catch {}
        },

        // Honest display formatters: never fabricate 0 for missing data
        formatMs(value) {
            return formatMilliseconds(value);
        },

        formatKbps(value) {
            return Number.isFinite(value) && value >= 0
                ? `${Math.round(value)} kbps`
                : 'Not measured';
        },

        formatHz(value) {
            return Number.isFinite(value) && value >= 0
                ? `${Math.round(value)} Hz`
                : 'Not measured';
        },

        formatTokens(input, output) {
            if (!Number.isFinite(input) && !Number.isFinite(output)) return 'Not measured';
            return `${input ?? 0} in / ${output ?? 0} out`;
        },

        formatCountdown() {
            if (this.remainingSeconds === null) return '--:--';
            const m = Math.floor(this.remainingSeconds / 60);
            const s = this.remainingSeconds % 60;
            return `${m}:${s < 10 ? '0' : ''}${s}`;
        },
    };
}
