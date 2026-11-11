@extends('layouts.auth')
@section('title', 'Find your voice — Voca')
@section('form')
    <span class="eyebrow auth-eyebrow"><span class="signal-dot"></span> HELLO, NEW POSSIBILITIES.</span>
    <h1>Find your voice<span class="grape-text">.</span></h1>
    <p class="auth-intro">Your business’s next chapter starts with a workspace.</p>
    <x-form-errors />
    <form method="POST" action="{{ route('register') }}" class="auth-form" x-data="{ show: false }">
        @csrf
        <div class="form-row">
            <div class="field"><label for="name">Your name</label><input id="name" name="name"
                    value="{{ old('name') }}" required maxlength="100" autocomplete="name"
                    placeholder="Alex Morgan" @error('name') aria-invalid="true" @enderror></div>
            <div class="field"><label for="organization_name">Business name</label><input id="organization_name"
                    name="organization_name" value="{{ old('organization_name') }}" required maxlength="100"
                    autocomplete="organization" placeholder="Your good company"
                    @error('organization_name') aria-invalid="true" @enderror></div>
        </div>
        <div class="field"><label for="email">Email address</label><input id="email" type="email"
                name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email"
                placeholder="you@company.com" @error('email') aria-invalid="true" @enderror></div>
        <div class="field"><label for="password">Password</label>
            <div class="password-field"><input id="password" type="password" :type="show ? 'text' : 'password'"
                    name="password" required minlength="12" autocomplete="new-password"
                    aria-describedby="password-help" @error('password') aria-invalid="true" @enderror><button
                    type="button" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"
                    x-text="show ? 'Hide' : 'Show'">Show</button></div><span class="field-help"
                id="password-help">12+ characters, with letters and a number. Maximum 72 bytes.</span>
        </div>
        <div class="field"><label for="password_confirmation">Confirm password</label><input
                id="password_confirmation" type="password" :type="show ? 'text' : 'password'"
                name="password_confirmation" required minlength="12" autocomplete="new-password"></div>
        <button class="button button--purple auth-submit" type="submit">Create my workspace <x-icon /></button>
        <p class="auth-privacy">We use your details to run your workspace. <a href="{{ route('privacy') }}">How
                your data is handled</a></p>
    </form>
    <p class="auth-switch">Already found your voice? <a href="{{ route('login') }}">Log in <span>↗</span></a></p>
@endsection
