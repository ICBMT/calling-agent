@extends('layouts.workspace')
@section('page-title', $agent->exists ? 'Edit agent' : 'New agent')
@section('content')
    <a href="{{ route('workspace.agents.index') }}" class="workspace-back">
        ← All agents</a>
    <div class="workspace-page-heading">
        <div><span class="eyebrow">LET’S GIVE YOUR IDEAS A VOICE.</span>
            <h1>{{ $agent->exists ? $agent->name : 'Meet your next teammate' }}<span class="grape-text">.</span>
            </h1>
            <p>{{ $agent->exists ? 'A little fine-tuning goes a long way.' : 'A name, a purpose, a little personality. Start there.' }}
            </p>
        </div><span class="status-tag status-tag--large">Draft agent</span>
    </div>
    <x-form-errors />
    @php($canManage = auth()->user()->can('manage', $workspace))
    <form method="POST"
        action="{{ $agent->exists ? route('workspace.agents.update', $agent) : route('workspace.agents.store') }}"
        class="agent-builder" x-data="agentBuilder">
        @csrf
        @if ($agent->exists)
            @method('PUT')
        @endif
        <fieldset @disabled(!$canManage)>
            <section class="workspace-panel form-panel">
                <div class="form-section-heading"><span>01</span>
                    <div>
                        <h2>The first hello</h2>
                        <p>Give your new agent an identity and a job to do.</p>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label for="name">Agent name <span>*</span></label><input
                            id="name" name="name" required maxlength="100"
                            value="{{ old('name', $agent->name) }}"
                            placeholder="e.g. Maya, your front-desk assistant"
                            @error('name') aria-invalid="true" @enderror></div>
                    <div class="field"><label for="purpose">What’s their role? <span>*</span></label><select
                            id="purpose" name="purpose" required>
                            @foreach (['receptionist' => 'Receptionist', 'support' => 'Customer support', 'appointments' => 'Appointment assistant', 'sales' => 'Sales & lead qualification', 'custom' => 'Something of your own'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('purpose', $agent->purpose) === $value)>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field"><label for="description">A quick introduction <span
                            class="optional-label">OPTIONAL</span></label><input id="description"
                        name="description" maxlength="500" value="{{ old('description', $agent->description) }}"
                        placeholder="What should your team know about this agent?"></div>
                @if ($canManage)
                    <div class="preset-bar">
                        <span class="preset-bar-label"><x-icon name="sparkles" />Start from a proven
                            pattern:</span>
                        <div class="preset-bar-buttons">
                            @foreach (['receptionist' => 'Receptionist', 'support' => 'Customer support', 'appointments' => 'Appointments', 'sales' => 'Sales'] as $presetKey => $presetLabel)
                                <button type="button" class="preset-button"
                                    @click="preset('{{ $presetKey }}')">{{ $presetLabel }}</button>
                            @endforeach
                        </div>
                        <small class="field-hint">One click fills the role, opening line and realistic call-handling
                            instructions. Review and edit everything before saving.</small>
                    </div>
                @endif
            </section>
            <section class="workspace-panel form-panel">
                <div class="form-section-heading"><span>02</span>
                    <div>
                        <h2>Make it feel like you</h2>
                        <p>Set the tone for the kind of conversation you want.</p>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label for="personality">Personality <span>*</span></label><select
                            id="personality" name="personality" required>
                            @foreach (['friendly' => 'Warm & friendly', 'professional' => 'Polished & professional', 'empathetic' => 'Patient & empathetic', 'concise' => 'Clear & concise'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('personality', $agent->personality ?? 'friendly') === $value)>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field"><label for="language">Preferred language <span>*</span></label><select
                            id="language" name="language" required>
                            @foreach (['en' => 'English', 'ur' => 'Urdu · اردو', 'hi' => 'Hindi · हिन्दी'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('language', $agent->language ?? $workspace->default_language) === $value)>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="form-section-note"><x-icon name="info" />This saves a language preference. Actual
                    speech and voices will depend on a verified provider.</p>
                <div class="field">
                    <div class="field-label-row"><label for="greeting">The opening line <span
                                class="optional-label">OPTIONAL</span></label>
                        @if ($canManage)
                            <button type="button" class="template-button" @click="suggestGreeting()"><x-icon
                                    name="sparkles" />Suggest an opening line</button>
                        @endif
                    </div>
                    <textarea id="greeting" name="greeting" rows="2" maxlength="500"
                        placeholder="Hello! Thanks for calling. How can I make your day easier?">{{ old('greeting', $agent->greeting) }}</textarea><span class="field-help">Make it welcoming. Disclose that this is an
                        AI assistant before a real call.</span>
                </div>
            </section>
            <section class="workspace-panel form-panel">
                <div class="form-section-heading"><span>03</span>
                    <div>
                        <h2>A little direction</h2>
                        <p>The instructions that make a helpful agent, yours.</p>
                    </div>
                </div>
                <div class="field">
                    <div class="field-label-row"><label for="instructions">Agent instructions
                            <span>*</span></label><button type="button" class="template-button"
                            @click="template()"><x-icon name="sparkles" />Use a starter template</button></div>
                    <textarea id="instructions" name="instructions" required minlength="20" maxlength="12000" rows="13"
                        aria-describedby="instructions-help"
                        placeholder="Who is your agent? What should they help with? What should they never make up?">{{ old('instructions', $agent->instructions) }}</textarea><span class="field-help" id="instructions-help">20–12,000
                        characters. Add your business facts, boundaries, escalation rules, and call-ending
                        guidance. This is a saved draft, not a live prompt test.</span>
                </div>
            </section>
        </fieldset>
        <div class="builder-bottom"><span><x-icon name="lock" />Private to {{ $workspace->name }}. Saved as a
                draft.</span>
            @if ($canManage)
                <button type="submit"
                    class="button button--purple">{{ $agent->exists ? 'Save changes' : 'Save my draft agent' }}<x-icon
                    name="check" /></button>@else<span class="status-tag">Read-only member access</span>
            @endif
        </div>
    </form>
    @if ($agent->exists && $canManage)
        <form method="POST" action="{{ route('workspace.agents.destroy', $agent) }}" class="agent-delete-form"
            data-confirm="Remove this agent? Existing call records will be kept. This action cannot be undone in the interface.">
            @csrf @method('DELETE')<button type="submit"><x-icon name="trash" />Remove this
                agent</button><span>Existing call records are retained.</span></form>
    @endif
@endsection
