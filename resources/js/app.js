import Alpine from 'alpinejs';
import { microphoneLab } from './workspace/microphone.js';
import { voicePlayground } from './workspace/voice/playground.js';

const root = document.documentElement;
root.classList.replace('no-js', 'js');
const systemMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
let savedMotion;
try {
    savedMotion = localStorage.getItem('voca-motion');
} catch {
    /* Storage is optional. */
}

Alpine.store('motion', {
    paused: savedMotion === 'off' || (savedMotion !== 'on' && systemMotion.matches),
    apply() {
        root.dataset.motion = this.paused ? 'reduced' : 'full';
        window.dispatchEvent(new CustomEvent('voca:motion', { detail: { paused: this.paused } }));
    },
    toggle() {
        this.paused = !this.paused;
        try {
            localStorage.setItem('voca-motion', this.paused ? 'off' : 'on');
        } catch {
            /* Storage is optional. */
        }
        this.apply();
    },
});
Alpine.store('motion').apply();
systemMotion.addEventListener('change', (event) => {
    // Respect an OS preference change even if motion was enabled earlier.
    Alpine.store('motion').paused = event.matches;
    Alpine.store('motion').apply();
});
Alpine.data('workspaceNavigation', () => ({
    sidebar: false,
    mobile: window.matchMedia('(max-width: 650px)').matches,
    media: null,
    mediaListener: null,
    init() {
        this.media = window.matchMedia('(max-width: 650px)');
        this.mediaListener = (event) => {
            this.mobile = event.matches;
            this.sidebar = false;
        };
        this.media.addEventListener('change', this.mediaListener);
    },
    toggle() {
        this.sidebar = !this.sidebar;
        if (this.sidebar)
            this.$nextTick(() => document.querySelector('.workspace-nav-close')?.focus());
    },
    close() {
        if (!this.sidebar) return;
        this.sidebar = false;
        this.$nextTick(() => this.$refs.toggle?.focus());
    },
    trapFocus(event) {
        if (!this.mobile || !this.sidebar) return;
        const nodes = [
            ...document
                .getElementById('workspace-navigation')
                .querySelectorAll('a[href], button:not([disabled])'),
        ];
        const first = nodes[0],
            last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
    destroy() {
        this.media?.removeEventListener('change', this.mediaListener);
    },
}));
Alpine.data('microphoneLab', microphoneLab);
Alpine.data('voicePlayground', voicePlayground);
Alpine.data('useCases', () => ({
    active: 0,
    cases: [
        {
            kicker: 'FIRST IMPRESSIONS. REIMAGINED.',
            title: 'The warmest welcome.<br>Without the wait.',
            description:
                'Picture a front desk that knows your business, welcomes every caller, and helps the right people find the right next step.',
            label: 'A better first hello.',
            secondary: 'A natural extension of your team.',
        },
        {
            kicker: 'A LITTLE MORE UNDERSTANDING.',
            title: 'Less explaining.<br>More feeling heard.',
            description:
                'Imagine customer care that starts with listening. Helpful answers, thoughtful follow-ups, and a clear path to a human when it matters.',
            label: 'Care that starts with listening.',
            secondary: 'People first. Always.',
        },
        {
            kicker: 'MAKE ROOM FOR WHAT MATTERS.',
            title: 'Good timing.<br>Great conversations.',
            description:
                'Plan an assistant that helps people find their next appointment, understand your opening hours, and get to the right person with less back-and-forth.',
            label: 'Make space for the next hello.',
            secondary: 'A little less back-and-forth.',
        },
        {
            kicker: 'OPPORTUNITY IS CALLING.',
            title: 'More potential.<br>Less phone tag.',
            description:
                'Shape an agent around understanding a caller’s needs, asking thoughtful questions, and helping your team focus on the right next conversation.',
            label: 'A more thoughtful first step.',
            secondary: 'Built around real needs.',
        },
    ],
    select(index, focus = false) {
        this.active = index;
        if (focus) this.$nextTick(() => document.getElementById(`case-tab-${index}`)?.focus());
    },
    next(direction) {
        this.select((this.active + direction + this.cases.length) % this.cases.length, true);
    },
}));
Alpine.data('agentBuilder', () => ({
    name: '',
    purpose: 'receptionist',
    personality: 'friendly',
    template() {
        const form = this.$el.closest('form') || this.$el;
        const name = form.querySelector('[name="name"]')?.value.trim() || 'a business assistant';
        const purpose =
            form
                .querySelector('[name="purpose"]')
                ?.selectedOptions[0]?.textContent.trim()
                .toLowerCase() || 'receptionist';
        const personality = form.querySelector('[name="personality"]')?.value || 'friendly';
        const field = form.querySelector('[name="instructions"]');
        if (!field) return;
        if (
            field.value.trim() &&
            !window.confirm('Replace the current instructions with a starter template?')
        )
            return;
        field.value = `You are ${name}, a ${personality} ${purpose}.\n\nYour goal\nWelcome callers and understand what they need. Keep answers clear and conversational. Ask one relevant question at a time.\n\nAccuracy\nUse only verified business information. If you do not know something, say so. Never invent prices, policies, or actions.\n\nHuman handoff\nWhen a caller needs a person, explain that you will help them reach the team. Do not claim a transfer or booking succeeded without confirmation.\n\nEnding the conversation\nCheck whether the caller needs anything else, then end politely.\n\nBusiness knowledge\nAdd your business facts and working hours here before connecting a provider.`;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.focus();
    },
}));
Alpine.data('providerSelection', () => ({
    models: [],
    model: '',
    voice: '',
    init() {
        try {
            this.models = JSON.parse(
                this.$el.querySelector('[data-provider-catalog]').textContent,
            ).models;
            this.model = this.$el.querySelector('[name="selected_model"]').value;
            this.voice = this.$el.querySelector('[name="selected_voice"]')?.value || '';
            this.modelChanged();
        } catch {
            this.models = [];
        }
    },
    allows(id) {
        return (
            this.models.find((model) => model.id === this.model)?.voice_ids.includes(id) || false
        );
    },
    modelChanged() {
        if (!this.allows(this.voice)) this.voice = '';
    },
}));
window.Alpine = Alpine;
Alpine.start();

const dialog = document.getElementById('voice-lab');
if (dialog) {
    document
        .querySelectorAll('[data-open-voice-lab]')
        .forEach((button) => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelector('[data-close-voice-lab]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => window.dispatchEvent(new Event('voca:stop-microphone')));
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (
            event.clientX < box.left ||
            event.clientX > box.right ||
            event.clientY < box.top ||
            event.clientY > box.bottom
        )
            dialog.close();
    });
}

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

if (document.querySelector('.marketing-page:not(.privacy-page)')) {
    import('./marketing/index.js')
        .then(({ initMarketing }) => initMarketing())
        .catch(() => {
            // The semantic page and CSS sculpture remain usable if animation assets fail.
            root.dataset.motion = 'reduced';
        });
}
