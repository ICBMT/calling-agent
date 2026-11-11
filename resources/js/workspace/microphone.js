export function microphoneLab() {
    let stream = null;
    let context = null;
    let source = null;
    let analyser = null;
    let frame = null;
    let generation = 0;
    let visibilityHandler;
    let pageHandler;

    return {
        active: false,
        requesting: false,
        error: '',
        init() {
            this.$nextTick(() => this.drawIdle());
            visibilityHandler = () => {
                if (document.hidden) this.stop();
            };
            pageHandler = () => this.stop();
            document.addEventListener('visibilitychange', visibilityHandler);
            window.addEventListener('pagehide', pageHandler);
        },
        drawIdle() {
            const canvas = this.$refs.canvas;
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#b89cdb';
            for (let x = 68; x < canvas.width - 68; x += 12) {
                ctx.beginPath();
                ctx.roundRect(x, canvas.height / 2 - 3, 4, 6, 2);
                ctx.fill();
            }
        },
        async start() {
            if (this.requesting || this.active) return;
            this.error = '';
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                this.error =
                    'Microphone access needs HTTPS and a supported browser. Open Voca in a secure tab and try again.';
                return;
            }
            this.requesting = true;
            const attempt = ++generation;
            try {
                const acquired = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true },
                    video: false,
                });
                // Closing a dialog while permission is pending must not leave a mic open.
                if (attempt !== generation || document.hidden) {
                    acquired.getTracks().forEach((track) => track.stop());
                    return;
                }
                stream = acquired;
                context = new (window.AudioContext || window.webkitAudioContext)();
                await context.resume();
                if (attempt !== generation) {
                    acquired.getTracks().forEach((track) => track.stop());
                    return;
                }
                source = context.createMediaStreamSource(stream);
                analyser = context.createAnalyser();
                analyser.fftSize = 256;
                source.connect(analyser); // Never connect to speakers: no feedback loop.
                this.active = true;
                stream
                    .getTracks()
                    .forEach((track) =>
                        track.addEventListener('ended', () => this.stop(), { once: true }),
                    );
                this.draw();
            } catch (error) {
                if (attempt !== generation) return;
                this.stop();
                const messages = {
                    NotAllowedError:
                        'Microphone permission was not granted. Allow microphone access in your browser settings, then try again. In an embedded preview, try opening the app in its own tab.',
                    NotFoundError: 'No microphone was found. Connect one and try again.',
                    NotReadableError:
                        'Your microphone is busy or unavailable. Close other audio apps and try again.',
                };
                this.error =
                    messages[error.name] ||
                    'We couldn’t start the microphone. Check your device and browser permissions.';
            } finally {
                if (attempt === generation) this.requesting = false;
            }
        },
        draw() {
            if (!this.active || !analyser) return;
            const canvas = this.$refs.canvas;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            const data = new Uint8Array(analyser.frequencyBinCount);
            analyser.getByteTimeDomainData(data);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#6d28d9';
            ctx.lineWidth = 3;
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';
            ctx.beginPath();
            const margin = 45;
            data.forEach((value, index) => {
                const x = margin + (index / (data.length - 1)) * (canvas.width - margin * 2);
                const y = canvas.height / 2 + ((value - 128) / 128) * 160;
                if (!index) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
            frame = requestAnimationFrame(() => this.draw());
        },
        stop() {
            ++generation;
            this.active = false;
            this.requesting = false;
            if (frame !== null) cancelAnimationFrame(frame);
            frame = null;
            source?.disconnect();
            source = null;
            analyser?.disconnect();
            analyser = null;
            stream?.getTracks().forEach((track) => track.stop());
            stream = null;
            if (context && context.state !== 'closed') context.close().catch(() => {});
            context = null;
            this.drawIdle();
        },
        destroy() {
            this.stop();
            document.removeEventListener('visibilitychange', visibilityHandler);
            window.removeEventListener('pagehide', pageHandler);
        },
    };
}
