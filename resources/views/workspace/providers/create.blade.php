@extends('layouts.workspace')
@section('page-title', $platformScope ? 'Add platform provider' : 'Add provider')
@section('content')
    <a href="{{ route($routePrefix . '.index') }}" class="workspace-back">
        ← Back to providers</a>
    <div class="workspace-page-heading">
        <div><span class="eyebrow">01 / YOUR CREDENTIALS, KEPT PRIVATE.</span>
            <h1>Bring {{ $definition['name'] }}<br>into the conversation<span class="grape-text">.</span></h1>
            <p>{{ $platformScope ? 'Platform scope: shared backend defaults, managed only by operators.' : 'Saved to this workspace only. Never to your browser’s storage.' }}
            </p>
        </div>
    </div>
    <x-form-errors />
    <div class="provider-detail-grid">
        <form method="POST" action="{{ route($routePrefix . '.store') }}" class="workspace-panel form-panel provider-form">@csrf
            <input type="hidden" name="provider" value="{{ $provider }}">
            <div class="form-section-heading"><span><x-icon name="shield" /></span>
                <div>
                    <h2>A secure first connection</h2>
                    <p>One category per connection. Reuse a provider through separate category configurations.</p>
                </div>
            </div>
            <div class="field"><label for="capability">Provider category</label><select id="capability" name="capability"
                    required>
                    @foreach ($capabilities as $capability)
                        <option value="{{ $capability->value }}" @selected(old('capability') === $capability->value)>{{ $capability->label() }}
                        </option>
                    @endforeach
                </select><span class="field-help">Native realtime is separate from a modular STT → LLM → TTS
                    pipeline.</span></div>
            @include('workspace.providers.credential-fields')
            <div class="provider-form-actions"><button type="submit" class="button button--purple button--small">Save
                    encrypted credential <x-icon name="shield" /></button></div>
            <p class="provider-fine-print">Save first, then run Test Connection. No provider request is made when saving.
                Never enter keys in names, instructions, or support messages.</p>
        </form>
        @include('workspace.providers.instructions')
    </div>
@endsection
