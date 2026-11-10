@extends('layouts.auth')
@section('title', 'Welcome back — Voca')
@section('form')
    <span class="eyebrow auth-eyebrow"><span class="signal-dot"></span> BACK IN YOUR ELEMENT.</span>
    <h1>Hello again<span class="grape-text">.</span></h1>
    <p class="auth-intro">Good to have you back. Let’s pick up the conversation.</p>
    <x-form-errors />
    <form method="POST" action="{{ route('login') }}" class="auth-form" x-data="{ show: false }">
        @csrf
        <div class="field"><label for="email">Email address</label><input id="email" type="email"
                name="email" value="{{ old('email') }}" required autocomplete="email" autofocus
                placeholder="you@company.com" @error('email') aria-invalid="true" @enderror></div>
        <div class="field"><label for="password">Password</label>
            <div class="password-field"><input id="password" type="password" :type="show ? 'text' : 'password'"
                    name="password" required autocomplete="current-password"><button type="button"
                    @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"
                    x-text="show ? 'Hide' : 'Show'">Show</button></div>
        </div>
        <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in on
            this device</label>
        <button class="button button--purple auth-submit" type="submit">Back to my workspace <x-icon /></button>
    </form>
    <p class="auth-switch">New around here? <a href="{{ route('register') }}">Find your voice <span>↗</span></a>
    </p>
@endsection
