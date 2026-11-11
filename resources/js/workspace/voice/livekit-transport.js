import {
    AudioPresets,
    createLocalAudioTrack,
    Room,
    RoomEvent,
    setLogLevel,
    Track,
} from 'livekit-client';
import { audioPerformance } from './performance.js';

// No room tokens, SDP, raw SDK exceptions or content forwarded to browser consoles/analytics.
setLogLevel('silent');

export function acquireMicrophone() {
    return createLocalAudioTrack({
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
        channelCount: 1,
    });
}

function timeout(promise, milliseconds) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('timeout')), milliseconds);
        }),
    ]).finally(() => clearTimeout(timer));
}

export class VoiceTransport {
    constructor(connection, sessionId, microphone, container, callbacks) {
        this.expected = connection;
        this.sessionId = sessionId;
        this.microphone = microphone;
        this.container = container;
        this.callbacks = callbacks;
        this.closed = false;
        this.held = false;
        this.remote = new Map();
        this.previousSender = null;
        this.room = new Room({
            adaptiveStream: false,
            dynacast: false,
            disconnectOnPageLeave: true,
            publishDefaults: {
                audioPreset: AudioPresets.speech,
                dtx: true,
                red: true,
                forceStereo: false,
                stopMicTrackOnMute: true,
            },
            reconnectPolicy: {
                nextRetryDelayInMs({ retryCount, elapsedMs }) {
                    if (elapsedMs > 15000 || retryCount >= 5) return null;
                    return Math.min(500 * 2 ** retryCount, 3000);
                },
            },
        });
        const room = this.room;
        const subscribe = (publication, participant) => {
            if (
                participant.identity === connection.agent_identity &&
                publication.kind === Track.Kind.Audio
            )
                publication.setSubscribed(true);
        };
        room.on(RoomEvent.ParticipantConnected, (participant) => {
            participant.trackPublications.forEach((publication) =>
                subscribe(publication, participant),
            );
        });
        room.on(RoomEvent.TrackPublished, subscribe);
        room.on(RoomEvent.TrackSubscribed, (track, _publication, participant) => {
            if (
                this.closed ||
                participant.identity !== connection.agent_identity ||
                track.kind !== Track.Kind.Audio
            )
                return;
            const element = track.attach();
            element.setAttribute('playsinline', '');
            element.autoplay = true;
            element.muted = this.held;
            this.container.appendChild(element);
            this.remote.set(track.sid, { track, element });
            callbacks.audioAttached();
        });
        room.on(RoomEvent.TrackUnsubscribed, (track) => this.detach(track.sid));
        room.on(RoomEvent.TrackSubscriptionFailed, () => callbacks.failed('connection_error'));
        room.on(RoomEvent.DataReceived, (bytes, participant, _kind, topic) => {
            if (
                !this.closed &&
                participant?.identity === connection.agent_identity &&
                topic === 'voca.events'
            )
                callbacks.packet(bytes);
        });
        const reconnecting = () => {
            if (this.closed) return;
            this.holdAudio(true);
            callbacks.reconnecting();
        };
        room.on(RoomEvent.Reconnecting, reconnecting);
        room.on(RoomEvent.SignalReconnecting, reconnecting);
        room.on(RoomEvent.Reconnected, async () => {
            if (this.closed) return;
            try {
                // Cancel pre-drop output before unmuting; never replay a stale response on recovery.
                await this.interrupt();
                if (this.closed) return;
                this.holdAudio(false);
                callbacks.reconnected();
            } catch {
                callbacks.failed('browser_disconnected');
            }
        });
        room.on(RoomEvent.Disconnected, () => {
            if (!this.closed) callbacks.failed('browser_disconnected');
        });
        room.on(RoomEvent.AudioPlaybackStatusChanged, () =>
            callbacks.playback(room.canPlaybackAudio),
        );
        room.on(RoomEvent.ConnectionQualityChanged, (quality, participant) => {
            if (participant.identity === room.localParticipant.identity) callbacks.quality(quality);
        });
        room.on(RoomEvent.MediaDevicesError, () => callbacks.failed('microphone_busy'));
    }

    async connect() {
        const connection = this.expected;
        // Best-effort autoplay unlock; a visible Enable speaker button handles browser rejection.
        this.room.startAudio().catch(() => this.callbacks.playback(false));
        await this.room.connect(connection.url, connection.token, {
            autoSubscribe: false,
            maxRetries: 0,
            websocketTimeout: 10000,
            peerConnectionTimeout: 15000,
        });
        if (this.closed) return;
        if (
            this.room.name !== connection.room ||
            this.room.localParticipant.identity !== connection.identity
        )
            throw new Error('Room binding rejected.');
        this.room.remoteParticipants.forEach((participant) => {
            if (participant.identity === connection.agent_identity)
                participant.audioTrackPublications.forEach((publication) =>
                    publication.setSubscribed(true),
                );
        });
        await this.room.localParticipant.publishTrack(this.microphone, {
            source: Track.Source.Microphone,
        });
        if (this.closed) {
            this.microphone.stop();
            return;
        }
        this.callbacks.playback(this.room.canPlaybackAudio);
    }

    holdAudio(held) {
        this.held = held;
        this.remote.forEach(({ element }) => {
            element.muted = held;
        });
    }

    async interrupt() {
        this.holdAudio(true);
        try {
            const reply = await timeout(
                this.room.localParticipant.performRpc({
                    destinationIdentity: this.expected.agent_identity,
                    method: 'voca.interrupt',
                    payload: JSON.stringify({ session_id: this.sessionId }),
                    responseTimeout: 8000,
                }),
                4500,
            );
            if (JSON.parse(reply).interrupted !== true)
                throw new Error('Cancellation not confirmed.');
            if (!this.closed) this.holdAudio(false);
        } catch {
            // Keep old output locally silent until the controller tears down the session.
            throw new Error('Cancellation not confirmed.');
        }
    }

    async mute(muted) {
        if (muted) await this.microphone.mute();
        else await this.microphone.unmute();
        if (this.closed) this.microphone.stop(); // Late permission/reacquisition must not reopen a closed mic.
    }

    async enableSpeaker() {
        await this.room.startAudio();
        this.callbacks.playback(this.room.canPlaybackAudio);
    }

    async performance() {
        const reports = await Promise.all([
            this.microphone?.getRTCStatsReport?.().catch(() => null),
            ...[...this.remote.values()].map(({ track }) =>
                track?.getRTCStatsReport?.().catch(() => null),
            ),
        ]);
        const result = audioPerformance(reports, this.previousSender);
        this.previousSender = result.sender;
        const rate = this.microphone?.mediaStreamTrack?.getSettings?.()?.sampleRate;
        if (Number.isFinite(rate)) result.values.capture_hz = rate;
        return result.values;
    }

    detach(sid) {
        const remote = this.remote.get(sid);
        if (!remote) return;
        try {
            remote.element.pause();
        } catch {}
        try {
            remote.track.detach().forEach((element) => {
                element.srcObject = null;
                element.remove();
            });
        } catch {}
        this.remote.delete(sid);
    }

    close() {
        if (this.closed) return;
        this.closed = true;
        // Synchronous privacy boundary first; do not wait for a network/CSRF/cleanup response.
        try {
            this.microphone?.stop?.();
        } catch {}
        [...this.remote.keys()].forEach((sid) => this.detach(sid));
        this.room.removeAllListeners();
        this.expected.token = null;
        this.room.disconnect(true).catch(() => {});
    }
}
