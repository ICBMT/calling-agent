@extends('layouts.base')
@section('body-class', 'marketing-page')
@section('body')
    <div class="site-shell">
        <header class="site-header" x-data="{ open: false }" @keydown.escape.window="open = false">
            <a href="{{ route('home') }}" aria-label="Voca home"><x-logo /></a>
            <nav class="desktop-nav" aria-label="Main navigation">
                <a href="#platform">The platform</a>
                <a href="#how-it-works">How it works</a>
                <a href="#use-cases">Made for you</a>
            </nav>
            <div class="header-actions">
                @auth
                    <a href="{{ route('workspace.overview') }}" class="button button--dark button--small">My workspace
                        <x-icon /></a>
                @else
                    <a href="{{ route('login') }}" class="login-link">Log in <x-icon /></a>
                    <a href="{{ route('register') }}" class="button button--dark button--small">Let’s talk
                        <x-icon /></a>
                @endauth
                <button type="button" class="mobile-menu-button icon-button" @click="open = !open" :aria-expanded="open"
                    aria-controls="mobile-navigation" aria-label="Toggle menu"><x-icon name="menu" /></button>
            </div>
            <nav class="mobile-nav" id="mobile-navigation" x-show="open" x-cloak x-transition
                aria-label="Mobile navigation">
                <a href="#platform" @click="open = false">The platform <x-icon /></a>
                <a href="#how-it-works" @click="open = false">How it works <x-icon /></a>
                <a href="#use-cases" @click="open = false">Made for you <x-icon /></a>
                <a href="{{ route('login') }}">Log in <x-icon /></a>
            </nav>
        </header>

        <main id="main-content">
            <section class="hero" aria-labelledby="hero-heading">
                <div class="hero-topline"><span class="eyebrow"><span class="signal-dot"></span> A NEW FREQUENCY
                        FOR BUSINESS</span><span class="hero-edition">HUMAN FIRST. AI POWERED.</span></div>
                <div class="hero-grid">
                    <div class="hero-copy">
                        <h1 id="hero-heading"><span class="hero-line">Every call.</span><span class="hero-line">A
                                little more</span><span class="hero-line hero-human">human<span
                                    class="hero-period">.</span><svg class="human-underline" viewBox="0 0 460 28"
                                    fill="none" aria-hidden="true">
                                    <path d="M5 19C103 1 283 1 454 15M90 24C212 10 332 11 413 21" stroke="currentColor"
                                        stroke-width="6" stroke-linecap="round" />
                                </svg></span></h1>
                        <p class="hero-description">Less “please hold.” More real connection.<br>Meet AI voice
                            agents with a voice of your own.</p>
                        <div class="hero-buttons">
                            <a href="{{ route('register') }}" class="button button--lime magnetic">Build your
                                first agent <span class="button-icon"><x-icon /></span></a>
                            <button type="button" class="text-button" data-open-voice-lab><span class="play-ring"><x-icon
                                        name="wave" /></span> Explore the voice
                                lab</button>
                        </div>
                        <p class="hero-fineprint"><span class="tiny-spark">✳</span> Your personality. Your
                            providers. Your possibilities.</p>
                    </div>
                    <div class="hero-art">
                        <div class="orb-orbit orbit-one" aria-hidden="true"></div>
                        <div class="orb-orbit orbit-two" aria-hidden="true"></div>
                        <span class="art-coordinate coordinate-top" aria-hidden="true">V.01 / VOICE IN
                            MOTION</span>
                        <div class="sculpture-container" id="voice-sculpture" aria-hidden="true">
                            <div class="sculpture-fallback">
                                <div class="fallback-ring"></div>
                                <div class="fallback-core"></div>
                                <div class="fallback-satellite"></div>
                            </div>
                        </div>
                        <div class="floating-card connection-card" data-parallax="-0.7"><span class="card-spark"><x-icon
                                    name="sparkles" /></span>
                            <div><span class="micro-label">A LITTLE LESS ARTIFICIAL</span><strong>A lot more
                                    you.</strong></div><span class="card-mini-dot"></span>
                        </div>
                        <div class="floating-card voice-card" data-parallax="0.45">
                            <div class="voice-card-top"><span class="voice-card-avatar"><x-icon name="wave" /></span>
                                <div><strong>Made to listen.</strong><span>Not just to respond.</span></div><span
                                    class="voice-card-star">✳</span>
                            </div>
                            <div class="decorative-wave" aria-hidden="true">
                                @for ($i = 0; $i < 42; $i++)
                                    <i
                                        style="--bar-height: {{ 7 + (int) (abs(sin($i * 1.31)) * (14 + 14 * sin($i * 0.12))) }}px; --bar-delay: {{ $i * 0.04 }}s"></i>
                                @endfor
                            </div>
                            <div class="voice-card-bottom"><span>GOOD ENERGY. GREAT
                                    CONVERSATIONS.</span><span>↗</span></div>
                        </div>
                        <div class="orb-caption"><span class="crosshair">+</span><span>INTELLIGENCE, WITH A LITTLE
                                SOUL.</span><span class="crosshair">+</span></div>
                        <span class="small-candy candy-one" aria-hidden="true"></span><span class="small-candy candy-two"
                            aria-hidden="true"></span>
                    </div>
                </div>
                <div class="hero-bottom"><a href="#platform" class="scroll-cue"><span class="scroll-icon"><x-icon
                                name="arrow-down" /></span> SCROLL TO TUNE IN</a>
                    <div class="hero-pill"><span class="pill-dots" aria-hidden="true"><i></i><i></i><i></i></span> Built
                        for the way we actually talk.
                    </div><span class="hero-index">01 — HELLO, VOCA</span>
                </div>
            </section>

            <div class="provider-ribbon" aria-label="Planned provider ecosystem">
                <p>YOUR STACK.<br><strong>YOUR RULES.</strong><span>Provider ecosystem · in development</span></p>
                <div class="provider-wordmarks"><span class="wordmark-openai"><span aria-hidden="true">❋</span>
                        OpenAI</span><span class="wordmark-eleven">ıı ElevenLabs</span><span class="wordmark-twilio"><span
                            class="twilio-mark" aria-hidden="true">⠿</span>
                        twilio</span><span class="wordmark-livekit"><span aria-hidden="true">▥</span>
                        LiveKit</span><span class="wordmark-custom"><x-icon name="plus" /> Your own</span>
                </div>
            </div>

            <section id="platform" class="platform-section section-pad" aria-labelledby="platform-heading">
                <div class="section-heading reveal"><span class="eyebrow"><span class="mini-line"></span> LESS
                        FRICTION. MORE FEELING.</span>
                    <div class="heading-row">
                        <h2 id="platform-heading">Not another robot<br>on the line<span class="lime-text">.</span></h2>
                        <p>Your best conversations don’t follow a script.<br>Your AI shouldn’t feel like it
                            does.<br><span>That’s the future we’re building.</span></p>
                    </div>
                </div>
                <div class="feature-grid">
                    <article class="feature-card feature-card--voice reveal">
                        <div class="feature-top"><span class="feature-number">01 / NATURAL BY
                                DESIGN</span><x-icon name="wave" /></div>
                        <div class="voice-ripple-art" aria-hidden="true"><i></i><i></i><i></i><i></i>
                            <div class="ripple-center"><x-icon name="wave" /></div><span class="ripple-label">LESS
                                ROBOT. MORE RAPPORT.</span>
                        </div>
                        <h3>A voice. Not a voicemail.</h3>
                        <p>Designed for flowing conversations, thoughtful pauses, and the freedom to interrupt.
                        </p><span class="feature-tag"><span></span> Real-time voice · on the roadmap</span>
                    </article>
                    <article class="feature-card feature-card--personality reveal">
                        <div class="feature-top"><span class="feature-number">02 / UNMISTAKABLY
                                YOU</span><x-icon name="sparkles" /></div>
                        <div class="personality-art" aria-hidden="true"><span
                                class="personality-pill personality-pill-one">A little friendly
                                <span>☺</span></span><span class="personality-pill personality-pill-two">A lot of
                                know-how <span>✳</span></span><span class="personality-pill personality-pill-three">100%
                                your energy
                                <span>↗</span></span></div>
                        <h3>Your brand has a voice.</h3>
                        <p>Give it a personality. Set its purpose. Write the instructions that make your agent
                            yours.</p><a class="feature-link" href="{{ route('register') }}">Create a draft
                            agent <x-icon name="arrow-right" /></a>
                    </article>
                    <article class="feature-card feature-card--control reveal">
                        <div class="feature-top"><span class="feature-number">03 / ON YOUR TERMS</span><x-icon
                                name="shield" /></div>
                        <div class="control-art" aria-hidden="true">
                            <div class="control-orbit"></div>
                            <div class="control-symbol"><x-logo /></div><span class="control-node node-a"><x-icon
                                    name="lock" /></span><span class="control-node node-b"><x-icon
                                    name="globe" /></span><span class="control-node node-c"><x-icon
                                    name="plus" /></span>
                        </div>
                        <h3>More choice. Less lock-in.</h3>
                        <p>A modular foundation for your models, your voice providers, and your business’s next
                            chapter.</p><span class="feature-tag"><span></span> Independent by
                            architecture</span>
                    </article>
                </div>
                <div class="platform-bottom"><span>GOOD TECH SHOULD FEEL SECOND NATURE.</span><span class="asterisk"
                        aria-hidden="true">✳</span><span>THAT’S THE WHOLE IDEA.</span></div>
            </section>

            <div class="ticker" aria-hidden="true">
                <div class="ticker-track">
                    @for ($i = 0; $i < 4; $i++)
                        <span>LESS HOLD MUSIC.</span><span class="ticker-star">✳</span><span>MORE
                            HELLO.</span><span class="ticker-star">✳</span>
                    @endfor
                </div>
            </div>

            <section id="how-it-works" class="workflow-section section-pad" aria-labelledby="workflow-heading">
                <div class="workflow-intro reveal"><span class="eyebrow"><span class="mini-line"></span> FROM
                        WHAT IF, TO WHAT’S NEXT.</span>
                    <h2 id="workflow-heading">Big possibilities.<br>A beautifully simple start<span
                            class="grape-text">.</span></h2>
                </div>
                <div class="workflow-grid">
                    <div class="workflow-steps">
                        <article class="workflow-step is-current" data-step="0"><span class="step-number">01</span>
                            <div><span class="step-kicker">MEET YOUR NEW TEAMMATE</span>
                                <h3>Give it a purpose.</h3>
                                <p>The front desk. The support line. The first hello. Create an agent around what
                                    your business needs.</p><span class="step-state">AVAILABLE NOW · DRAFT
                                    BUILDER</span>
                            </div>
                        </article>
                        <article class="workflow-step" data-step="1"><span class="step-number">02</span>
                            <div><span class="step-kicker">MAKE IT FEEL LIKE YOU</span>
                                <h3>A little personality<br>goes a long way.</h3>
                                <p>Choose a tone, add your instructions, and write that perfect opening line. All
                                    in one calm workspace.</p><span class="step-state">AVAILABLE NOW · AGENT
                                    CONFIGURATION</span>
                            </div>
                        </article>
                        <article class="workflow-step" data-step="2"><span class="step-number">03</span>
                            <div><span class="step-kicker">THE NEXT CHAPTER</span>
                                <h3>Connect. Test.<br>Then say hello.</h3>
                                <p>Provider connections, a real voice playground, and telephone calling are next.
                                    Built in stages. Tested for real.</p><span class="step-state step-state--next">COMING
                                    NEXT · VOICE & CONNECTIONS</span>
                            </div>
                        </article>
                    </div>
                    <div class="workflow-sticky">
                        <div class="product-preview" aria-label="Illustration of the agent configuration workflow">
                            <div class="preview-toolbar"><x-logo /><span>YOUR WORKSPACE</span><span
                                    class="preview-toolbar-dots">•••</span></div>
                            <div class="preview-inner">
                                <div class="preview-eyebrow"><span class="signal-dot"></span><span
                                        id="preview-step-label">01 / THE FIRST HELLO</span></div>
                                <div class="preview-avatar"><x-icon name="wave" /><span class="avatar-spark">✳</span>
                                </div>
                                <h4 id="preview-title">Meet your next<br>great conversation.</h4>
                                <p id="preview-description">A new kind of teammate.<br>With your kind of energy.
                                </p>
                                <div class="preview-chips"><span id="preview-chip-one">Friendly</span><span
                                        id="preview-chip-two">Receptionist</span><span class="preview-draft"
                                        id="preview-chip-three">Draft</span></div>
                                <div class="preview-instruction"><span class="micro-label" id="preview-note-label">YOUR
                                        INSTRUCTIONS</span>
                                    <p id="preview-note">“Be helpful. Be thoughtful.<br>Make every caller feel
                                        welcome.”</p><span class="preview-note-spark">↗</span>
                                </div>
                                <div class="preview-progress"><span
                                        class="is-current"></span><span></span><span></span><span
                                        class="preview-progress-label">A LITTLE MORE YOU.</span></div>
                            </div>
                        </div>
                        <div class="preview-sticker" aria-hidden="true">GOOD<br>CALL<span>↗</span></div>
                    </div>
                </div>
            </section>

            <section id="use-cases" class="usecase-section section-pad" aria-labelledby="usecase-heading"
                x-data="useCases">
                <div class="section-heading reveal"><span class="eyebrow"><span class="mini-line"></span> A NEW
                        VOICE. IN YOUR WORLD.</span>
                    <div class="heading-row">
                        <h2 id="usecase-heading">One platform.<br>So many good calls<span class="grape-text">.</span></h2>
                        <p>For the big moments.<br>The everyday questions.<br>And everything in between.</p>
                    </div>
                </div>
                <div class="usecase-tabs" role="tablist" aria-label="Explore use cases" @keydown.right.prevent="next(1)"
                    @keydown.left.prevent="next(-1)" @keydown.home.prevent="select(0, true)"
                    @keydown.end.prevent="select(3, true)">
                    @foreach (['The front desk', 'Customer care', 'Appointments', 'Sales & leads'] as $case)
                        <button type="button" role="tab" id="case-tab-{{ $loop->index }}"
                            aria-controls="usecase-panel" :aria-selected="active === {{ $loop->index }}"
                            :tabindex="active === {{ $loop->index }} ? 0 : -1" @click="select({{ $loop->index }})"
                            :class="{ 'is-active': active === {{ $loop->index }} }"><span
                                class="tab-number">0{{ $loop->iteration }}</span>{{ $case }}<x-icon /></button>
                    @endforeach
                </div>
                <div id="usecase-panel" role="tabpanel" :aria-labelledby="'case-tab-' + active" tabindex="0"
                    class="usecase-panel">
                    <div class="usecase-copy"><span class="usecase-kicker" x-text="cases[active].kicker">FIRST
                            IMPRESSIONS. REIMAGINED.</span>
                        <h3 x-html="cases[active].title">The warmest welcome.<br>Without the wait.</h3>
                        <p x-text="cases[active].description">Picture a front desk that knows your business,
                            welcomes every caller, and helps the right people find the right next step.</p><a
                            href="{{ route('register') }}" class="button button--dark">Start with your first
                            agent <x-icon /></a><span class="usecase-note">Explore a use case. Live calling is a
                            future milestone.</span>
                    </div>
                    <div class="usecase-art"><span class="usecase-art-ring ring-a"></span><span
                            class="usecase-art-ring ring-b"></span>
                        <div class="usecase-art-phone"><x-icon name="phone" /></div>
                        <div class="usecase-label label-one"><span class="signal-dot"></span><span
                                x-text="cases[active].label">A better first hello.</span></div>
                        <div class="usecase-label label-two"><x-icon name="sparkles" /><span
                                x-text="cases[active].secondary">A natural extension of your team.</span></div>
                        <span class="usecase-big-star" aria-hidden="true">✳</span><span class="usecase-art-caption">MORE
                            CONNECTION. LESS COMPLICATION.</span>
                    </div>
                </div>
            </section>

            <section class="principles-section section-pad">
                <div class="principles-heading reveal"><span class="eyebrow">BUILT WITH INTENTION.</span>
                    <h2>Good conversations.<br>Even better principles.</h2>
                </div>
                <div class="principles-grid">
                    <div class="reveal"><x-icon name="lock" />
                        <h3>Private by foundation.</h3>
                        <p>Isolated business workspaces. Protected sessions. No microphone access without your
                            say-so.</p>
                    </div>
                    <div class="reveal"><x-icon name="globe" />
                        <h3>Your language. Your voice.</h3>
                        <p>Start agent drafts in English, Urdu, or Hindi. Real voice availability will depend on
                            your connected provider.</p>
                    </div>
                    <div class="reveal"><x-icon name="shield" />
                        <h3>Real progress. No pretend.</h3>
                        <p>No invented call stats or “connected” badges. Just clear milestones and a foundation
                            you can build on.</p>
                    </div>
                </div>
            </section>

            <section id="questions" class="faq-section section-pad" aria-labelledby="faq-heading">
                <div class="faq-heading reveal"><span class="eyebrow"><span class="mini-line"></span> A FEW
                        GOOD QUESTIONS.</span>
                    <h2 id="faq-heading">Let’s clear<br>the air<span class="grape-text">.</span></h2>
                    <p>New possibilities. Honest answers.</p>
                </div>
                <div class="faq-list">
                    <details>
                        <summary>What can I do with Voca right now?<span class="faq-plus"><x-icon name="plus" /></span>
                        </summary>
                        <div>
                            <p>Create an account and your own business workspace, build and edit draft AI agents,
                                set their personality, language, instructions, and greeting, and check your
                                microphone locally. This is the foundation release—not a live calling service yet.
                            </p>
                        </div>
                    </details>
                    <details>
                        <summary>Can I make real AI calls today?<span class="faq-plus"><x-icon name="plus" /></span>
                        </summary>
                        <div>
                            <p>Not in this release. Secure provider configuration and read-only connection checks are
                                available, followed by a
                                LiveKit voice worker, browser conversations, and telephone integrations. Each will
                                be marked available only after real functionality tests pass.</p>
                        </div>
                    </details>
                    <details>
                        <summary>Can I bring my own AI and voice providers?<span class="faq-plus"><x-icon
                                    name="plus" /></span></summary>
                        <div>
                            <p>That’s the architecture. Voca is designed around independent provider adapters
                                rather than a single locked-in model. Credential management and actual connection
                                testing are available for OpenAI, Gemini, Deepgram, and ElevenLabs. Authentication checks
                                are not live voice or telephone tests.</p>
                        </div>
                    </details>
                    <details>
                        <summary>Will it work in English, Urdu, and Hindi?<span class="faq-plus"><x-icon
                                    name="plus" /></span></summary>
                        <div>
                            <p>You can save agent language preferences in all three now. Spoken languages, voices,
                                accents, and language switching must be checked against the provider you connect.
                                We won’t promise a voice capability before it’s verified.</p>
                        </div>
                    </details>
                    <details>
                        <summary>Does the voice lab record my audio?<span class="faq-plus"><x-icon
                                    name="plus" /></span></summary>
                        <div>
                            <p>No. The current voice lab is a local microphone check. It draws a waveform on your
                                device and doesn’t upload or save audio. Closing the lab or stopping the check
                                releases your microphone.</p>
                        </div>
                    </details>
                </div>
            </section>

            <section class="final-cta section-pad" aria-labelledby="cta-heading">
                <div class="cta-topline"><span class="eyebrow"><span class="signal-dot"></span> YOUR NEXT
                        CHAPTER STARTS WITH HELLO.</span><span class="cta-mark" aria-hidden="true">✳</span>
                </div>
                <h2 id="cta-heading">Let’s make<br><span>good calls.</span><a href="{{ route('register') }}"
                        class="cta-arrow magnetic" aria-label="Create your Voca workspace"><x-icon /></a></h2>
                <div class="cta-bottom">
                    <p>A little intelligence.<br>A lot of possibility. All yours.</p><a href="{{ route('register') }}"
                        class="button button--lime">Find your voice
                        <x-icon /></a><span class="cta-footnote">START WITH A WORKSPACE.<br>BUILD SOMETHING THAT
                        SOUNDS LIKE YOU.</span>
                </div>
            </section>
        </main>
        <footer class="site-footer">
            <div class="footer-top"><a href="{{ route('home') }}" aria-label="Voca home"><x-logo /></a><span>GOOD
                    TECHNOLOGY. HUMAN ENERGY.</span><a href="#main-content" class="back-to-top">Back to the good stuff
                    <x-icon name="arrow-up-right" /></a></div>
            <div class="footer-bottom"><span>© {{ date('Y') }} Voca. A new kind of calling.</span>
                <div><a href="{{ route('privacy') }}">Privacy</a><a href="#questions">Questions?</a><button
                        type="button" class="motion-toggle" x-data @click="$store.motion.toggle()"
                        :aria-pressed="$store.motion.paused"><span class="motion-toggle-dot"></span><span
                            x-text="$store.motion.paused ? 'Motion off' : 'Motion on'">Motion on</span></button>
                </div><span class="release-label">FOUNDATION RELEASE / MADE WITH INTENTION</span>
            </div>
        </footer>
    </div>
    <dialog id="voice-lab" class="voice-dialog" aria-labelledby="voice-lab-title" data-lenis-prevent>
        <div class="dialog-header"><span class="eyebrow">THE VOICE LAB / DEVICE CHECK</span><button type="button"
                class="icon-button" data-close-voice-lab aria-label="Close voice lab"><x-icon name="close" /></button>
        </div>
        <h2 id="voice-lab-title">Find your<br><span class="grape-text">frequency.</span></h2>
        <p class="dialog-description">Big conversations start with a little sound check.</p>
        <x-microphone-check /><a href="{{ route('register') }}" class="dialog-workspace-link">Ready for the
            next step? Create your workspace <x-icon /></a>
    </dialog>
@endsection
