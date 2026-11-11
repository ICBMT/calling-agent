<div class="mic-check" x-data="microphoneLab" @voca:stop-microphone.window="stop()">
    <div class="mic-visualizer" :class="{ 'is-listening': active }">
        <div class="mic-halo" aria-hidden="true"></div>
        <canvas x-ref="canvas" width="640" height="180" aria-hidden="true"></canvas>
        <span class="mic-status"
            x-text="active ? 'Listening to your microphone' : 'Your voice goes here'"></span>
    </div>
    <div class="mic-notice"><x-icon name="lock" />
        <p><strong>Local microphone check. Not an AI conversation.</strong> Your audio stays on this device.
            Nothing is recorded or uploaded.</p>
    </div>
    <p class="form-error" role="alert" x-show="error" x-text="error" x-cloak></p>
    <button type="button" class="button button--purple mic-start" @click="active ? stop() : start()"
        :disabled="requesting">
        <x-icon name="mic" />
        <span
            x-text="requesting ? 'Requesting permission…' : (active ? 'Stop microphone check' : 'Check my microphone')">Check
            my microphone</span>
    </button>
    <p class="microphone-footnote">Microphone permission is only requested when you click. Headphones
        recommended.</p>
</div>
