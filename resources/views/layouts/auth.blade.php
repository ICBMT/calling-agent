@extends('layouts.base')
@section('body-class', 'auth-page')
@section('body')
    <div class="auth-shell">
        <aside class="auth-story"><a href="{{ route('home') }}" aria-label="Voca home"><x-logo light /></a><span
                class="auth-story-eyebrow">GOOD TECHNOLOGY. HUMAN ENERGY.</span>
            <h2>Your next great<br>conversation<br><span>starts here.</span></h2>
            <div class="auth-art" aria-hidden="true"><span class="auth-art-orbit"></span><span
                    class="auth-art-orbit orbit-second"></span>
                <div class="auth-art-core"><x-icon name="wave" /></div><span class="auth-art-pill">A LITTLE MORE
                    YOU. <span>✳</span></span><span class="auth-art-spark">✳</span>
            </div>
            <div class="auth-story-footer"><span>YOUR WORKSPACE. YOUR VOICE.</span><span>↗</span></div>
        </aside>
        <main id="main-content" class="auth-main"><a class="auth-back" href="{{ route('home') }}"><span>←</span>
                Back to Voca</a>
            <div class="auth-form-wrap">@yield('form')</div>
            <div class="auth-main-footer"><span>Foundation release · Live calling is in development.</span><a
                    href="{{ route('privacy') }}">Privacy</a></div>
        </main>
    </div>
@endsection
