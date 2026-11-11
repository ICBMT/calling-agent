@extends('layouts.workspace')
@section('page-title', 'Settings')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">FEEL AT HOME.</span>
            <h1>Your workspace. Your way<span class="grape-text">.</span></h1>
            <p>A few small details that make it yours.</p>
        </div>
    </div>
    <x-form-errors />
    <form method="POST" action="{{ route('workspace.settings.update') }}" class="settings-form">@csrf
        @method('PUT')<section class="workspace-panel form-panel">
            <div class="form-section-heading"><span><x-icon name="building" /></span>
                <div>
                    <h2>The business basics</h2>
                    <p>Shared by everyone in this workspace.</p>
                </div>
            </div>
            <fieldset @disabled(!auth()->user()->can('manage', $workspace))>
                <div class="field"><label for="name">Business name</label><input id="name" name="name"
                        required maxlength="100" value="{{ old('name', $workspace->name) }}"></div>
                <div class="form-row">
                    <div class="field"><label for="timezone">Business timezone</label><select id="timezone"
                            name="timezone" required>
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}" @selected(old('timezone', $workspace->timezone) === $zone)>
                                    {{ str_replace('_', ' ', $zone) }}</option>
                            @endforeach
                        </select>
                        <span class="field-help">Used to display call timestamps.</span>
                    </div>
                    <div class="field"><label for="default_language">Default agent language</label><select
                            id="default_language" name="default_language">
                            @foreach (['en' => 'English', 'ur' => 'Urdu · اردو', 'hi' => 'Hindi · हिन्दी'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_language', $workspace->default_language) === $value)>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="field-help">A preference for new drafts, not a provider capability
                            claim.</span>
                    </div>
                </div>
            </fieldset>
            <div class="settings-form-bottom">
                @can('manage', $workspace)
                    <button type="submit" class="button button--purple button--small">Save workspace settings <x-icon
                        name="check" /></button>@else<span class="status-tag">Only an owner or admin can change
                        settings.</span>
                @endcan
            </div>
        </section>
    </form>

    <section class="workspace-panel form-panel" style="margin-top: 1.5rem;">
        <div class="form-section-heading"><span><x-icon name="phone" /></span>
            <div>
                <h2>Telephony & Inbound Webhooks</h2>
                <p>Configure your Twilio, Telnyx, or SIP carrier to forward calls to Voca.</p>
            </div>
        </div>
        <div class="health-list" style="margin-top: 1rem;">
            <div class="health-row">
                <span class="health-dot {{ config('telephony.enabled') ? 'is-ok' : '' }}"></span>
                <span>Telephony Gateway</span>
                <span class="health-status {{ config('telephony.enabled') ? 'is-ok' : '' }}">
                    {{ config('telephony.enabled') ? 'Enabled (' . ucfirst(config('telephony.driver')) . ')' : 'Disabled in .env' }}
                </span>
            </div>
            <div class="health-row">
                <span class="health-dot is-ok"></span>
                <span>Twilio Inbound URL</span>
                <span class="health-status"><code>{{ url('/api/telephony/inbound/twilio') }}</code></span>
            </div>
            <div class="health-row">
                <span class="health-dot is-ok"></span>
                <span>Twilio Status Callback URL</span>
                <span class="health-status"><code>{{ url('/api/telephony/status/twilio') }}</code></span>
            </div>
            <div class="health-row">
                <span class="health-dot is-ok"></span>
                <span>Telnyx Inbound Webhook</span>
                <span class="health-status"><code>{{ url('/api/telephony/inbound/telnyx') }}</code></span>
            </div>
        </div>
        <p class="field-help" style="margin-top: 1rem;">
            In your carrier dashboard (Twilio/Telnyx), configure your phone number's incoming voice webhook to HTTP POST with the Inbound URL above. Voca will verify signatures, log the call in your workspace, and bridge the call to LiveKit.
        </p>
    </section>

    <div class="workspace-info-note"><x-icon name="shield" />
        <p><strong>Your workspace is isolated.</strong> Other businesses can’t view your agents or call records.
            Roles are enforced on the server, not just hidden in the menu.</p>
    </div>
@endsection
