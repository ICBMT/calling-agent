import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';

gsap.registerPlugin(ScrollTrigger);

const story = [
    {
        label: '01 / THE FIRST HELLO',
        title: 'Meet your next<br>great conversation.',
        description: 'A new kind of teammate.<br>With your kind of energy.',
        chips: ['Friendly', 'Receptionist', 'Draft'],
        noteLabel: 'YOUR INSTRUCTIONS',
        note: '“Be helpful. Be thoughtful.<br>Make every caller feel welcome.”',
    },
    {
        label: '02 / YOUR KIND OF ENERGY',
        title: 'A little personality.<br>A lot more you.',
        description: 'Set the tone.<br>Make the first hello your own.',
        chips: ['Thoughtful', 'Your instructions', 'Your voice'],
        noteLabel: 'YOUR OPENING LINE',
        note: '“Hello! Thanks for calling.<br>How can I make your day easier?”',
    },
    {
        label: '03 / WHAT COMES NEXT',
        title: 'Built in stages.<br>Connected for real.',
        description: 'Real providers. Real conversations.<br>No pretend connection badges.',
        chips: ['Providers', 'Voice engine', 'Next milestone'],
        noteLabel: 'THE NEXT CHAPTER',
        note: 'Connect your providers.<br>Test the voice. Then make the call.',
    },
];

export function initMarketing() {
    let context;
    let lenis;
    let ticker;
    let magneticCleanup = [];
    let active = -1;

    function activateStory(index) {
        if (active === index) return;
        active = index;
        const step = story[index];
        document
            .querySelectorAll('.workflow-step')
            .forEach((element, i) => element.classList.toggle('is-current', i === index));
        document
            .querySelectorAll('.preview-progress > span:not(.preview-progress-label)')
            .forEach((element, i) => element.classList.toggle('is-current', i === index));
        const set = (id, value, html = false) => {
            const el = document.getElementById(id);
            if (el) {
                if (html) el.innerHTML = value;
                else el.textContent = value;
            }
        };
        set('preview-step-label', step.label);
        set('preview-title', step.title, true);
        set('preview-description', step.description, true);
        step.chips.forEach((text, i) =>
            set(['preview-chip-one', 'preview-chip-two', 'preview-chip-three'][i], text),
        );
        set('preview-note-label', step.noteLabel);
        set('preview-note', step.note, true);
    }
    const observer = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
            if (visible[0]) activateStory(Number(visible[0].target.dataset.step));
        },
        { rootMargin: '-25% 0px -25% 0px', threshold: [0, 0.2, 0.5, 0.75] },
    );
    document.querySelectorAll('.workflow-step').forEach((el) => observer.observe(el));
    activateStory(0);

    function stopMotion() {
        context?.revert();
        context = null;
        if (ticker) gsap.ticker.remove(ticker);
        ticker = null;
        lenis?.destroy();
        lenis = null;
        magneticCleanup.forEach((cleanup) => cleanup());
        magneticCleanup = [];
    }
    function startMotion() {
        stopMotion();
        if (document.documentElement.dataset.motion === 'reduced') return;
        // Touch remains native. Smooth only desktop wheel input, with native anchor semantics.
        if (window.matchMedia('(pointer: fine)').matches) {
            lenis = new Lenis({ duration: 1.05, smoothWheel: true, anchors: { offset: -25 } });
            lenis.on('scroll', ScrollTrigger.update);
            ticker = (time) => lenis?.raf(time * 1000);
            gsap.ticker.add(ticker);
        }
        context = gsap.context(() => {
            gsap.from('.hero-line', {
                y: 42,
                opacity: 0.2,
                duration: 1.12,
                ease: 'power4.out',
                stagger: 0.11,
                clearProps: 'all',
            });
            gsap.from('.hero-description, .hero-buttons, .hero-fineprint', {
                y: 20,
                opacity: 0.25,
                delay: 0.25,
                duration: 1,
                stagger: 0.09,
                ease: 'power3.out',
                clearProps: 'all',
            });
            gsap.from('.floating-card', {
                y: 25,
                opacity: 0,
                delay: 0.35,
                duration: 1.3,
                stagger: 0.15,
                ease: 'power3.out',
                clearProps: 'opacity',
            });
            gsap.utils.toArray('.reveal').forEach((el) => {
                gsap.from(el, {
                    y: 32,
                    opacity: 0.12,
                    duration: 0.9,
                    ease: 'power3.out',
                    scrollTrigger: { trigger: el, start: 'top 95%', once: true },
                    clearProps: 'all',
                });
            });
            gsap.utils.toArray('[data-parallax]').forEach((el) => {
                gsap.to(el, {
                    yPercent: Number(el.dataset.parallax) * 45,
                    ease: 'none',
                    scrollTrigger: {
                        trigger: '.hero',
                        start: 'top top',
                        end: 'bottom top',
                        scrub: 1.4,
                    },
                });
            });
            gsap.to('.preview-sticker', {
                rotation: 4,
                y: -12,
                ease: 'none',
                scrollTrigger: {
                    trigger: '.workflow-grid',
                    start: 'top 75%',
                    end: 'bottom 40%',
                    scrub: 1.2,
                },
            });
        });
        if (window.matchMedia('(pointer: fine) and (min-width: 850px)').matches) {
            document.querySelectorAll('.magnetic').forEach((el) => {
                const move = (event) => {
                    const bounds = el.getBoundingClientRect();
                    gsap.to(el, {
                        x: (event.clientX - bounds.left - bounds.width / 2) * 0.12,
                        y: (event.clientY - bounds.top - bounds.height / 2) * 0.18,
                        duration: 0.4,
                        ease: 'power2.out',
                    });
                };
                const leave = () =>
                    gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: 'elastic.out(1, .5)' });
                el.addEventListener('pointermove', move);
                el.addEventListener('pointerleave', leave);
                magneticCleanup.push(() => {
                    el.removeEventListener('pointermove', move);
                    el.removeEventListener('pointerleave', leave);
                    gsap.set(el, { clearProps: 'transform' });
                });
            });
        }
        ScrollTrigger.refresh();
    }
    const motionHandler = () => startMotion();
    window.addEventListener('voca:motion', motionHandler);
    startMotion();

    // Load WebGL separately so it never blocks semantic content, forms, or navigation.
    const loadSculpture = () =>
        import('./sculpture.js')
            .then(({ createSculpture }) =>
                createSculpture(document.getElementById('voice-sculpture')),
            )
            .catch(() => {});
    if ('requestIdleCallback' in window) requestIdleCallback(loadSculpture, { timeout: 900 });
    else setTimeout(loadSculpture, 150);
    window.addEventListener('pagehide', (event) => {
        if (event.persisted) return;
        stopMotion();
        observer.disconnect();
        window.removeEventListener('voca:motion', motionHandler);
    });
}
