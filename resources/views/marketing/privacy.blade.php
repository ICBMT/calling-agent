@extends('layouts.base')
@section('title', 'Privacy & your data — Voca')
@section('body-class', 'marketing-page privacy-page')
@section('body')
    <header class="site-header"><a href="{{ route('home') }}" aria-label="Voca home"><x-logo /></a><a href="{{ route('home') }}"
            class="button button--outline button--small">Back to Voca <x-icon /></a>
    </header>
    <main id="main-content" class="privacy-main"><span class="eyebrow">A LITTLE CLARITY GOES A LONG WAY.</span>
        <h1>Your data.<br>Your business<span class="grape-text">.</span></h1>
        <p>Foundation & secure providers · Updated November 10, 2026</p>
        <h2>What this release stores</h2>
        <p>Your account name, email, hashed password, organization membership, business settings, and the draft
            agents you save. Agent instructions and greetings are business content, so don’t include passwords or
            provider API keys in them. An audit trail records workspace and agent changes without saving request
            bodies, passwords, or credentials. Provider API keys entered through AI providers are encrypted at rest and
            never returned in ordinary pages or JSON.</p>
        <h2>Your microphone stays yours</h2>
        <p>The current voice lab is a local microphone check, not an AI call. Audio is analyzed in your browser
            only. It is not uploaded, recorded, or stored. Access is requested only when you start the check.
            Stopping, closing the lab, changing tabs, or leaving the page releases the microphone.</p>
        <h2>Sessions and local preferences</h2>
        <p>Voca uses essential session and CSRF cookies to protect sign-in and form submissions. Choosing “Keep me
            signed in” creates a persistent authentication cookie. Your motion preference is stored locally in
            your browser. This release does not include third-party analytics or advertising trackers. Fonts and
            application assets are self-hosted.</p>
        <h2>Business isolation and access</h2>
        <p>Workspace resources are scoped to authenticated organization membership. Owners and administrators can
            change settings, agents, and workspace provider credentials; members have read-only access. No default shared
            login or demo account is
            created.</p>
        <h2>Provider connection checks</h2>
        <p>When an authorized administrator runs Test Connection, the server sends the stored credential to the
            selected provider’s fixed HTTPS API and makes read-only authentication/catalog requests. Optional
            project balances or account allowances may be fetched. No text prompt or audio is generated. Voca
            stores a normalized model/voice catalog, safe result category, HTTP status, timestamp, and optional
            allowance snapshot—not the full provider response or raw error. The latest 20 checks are retained per
            configuration. Deleting a stored key disables local routing; revoke the original at the provider too.
            Encrypted copies may remain in operator-managed backups until those backups expire.</p>
        <p>Shared platform keys are separately managed by explicitly provisioned platform operators and can be
            resolved server-side for organizations without a local default. Organization users cannot inspect or
            manage those credentials. Operators must disclose the providers they enable and control access to
            the application encryption key, database, logs, and backups.</p>
        <h2>Calling and recordings</h2>
        <p>Real AI audio calling, telephone services, transcripts, and recording uploads
            are not implemented in this release. No phone calls or recordings are created by the current
            interface. Future voice and recording features will require explicit configuration, appropriate
            consent, and a documented retention policy before deployment.</p>
        <h2>Operating your installation</h2>
        <p>This is a self-hostable application foundation, not a complete legal privacy policy for every operator.
            The installation owner controls hosting, backups, retention, user requests, and any future providers.
            Before a public production deployment, publish your legal identity, privacy contact, retention and
            deletion procedures, applicable legal basis, and provider/subprocessor disclosures.</p>
        <p style="margin-top: 28px"><a href="{{ route('home') }}">← Back to the good stuff</a></p>
    </main>
@endsection
