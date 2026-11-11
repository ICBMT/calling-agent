@extends('layouts.workspace')
@section('page-title', $platformScope ? 'Platform provider connection' : 'Provider connection')
@section('content')
    <a href="{{ route($routePrefix . '.index') }}" class="workspace-back">
        ← Back to providers</a>
    <div class="workspace-page-heading">
        <div><span class="eyebrow">{{ $definition['name'] }} / {{ $platformScope ? 'PLATFORM' : 'WORKSPACE' }}</span>
            <h1>{{ $configuration->name }}<span class="grape-text">.</span></h1>
            <p>{{ $capability->label() }} · Credential version {{ $configuration->credential_version }} ·
                {{ $configuration->enabled ? 'Enabled' : 'Disabled' }}</p>
        </div><span @class([
            'status-tag',
            'status-tag--good' => $configuration->freshCatalog(),
        ])>{{ $configuration->statusLabel() }}</span>
    </div>
    <x-form-errors />
    @if (!$canManage)
        <div class="workspace-info-note"><x-icon name="shield" />
            <p><strong>Read-only access.</strong> Only a workspace owner or admin can replace keys, test connections, or
                change model/routing choices.</p>
        </div>
    @endif
    <div class="provider-detail-grid">
        <div class="provider-detail-main">
            <section class="workspace-panel form-panel provider-check-panel" aria-labelledby="connection-check">
                <div class="form-section-heading"><span><x-icon name="wave" /></span>
                    <div>
                        <h2 id="connection-check">A real check. A precise result.</h2>
                        <p>Authenticated read-only requests to {{ $definition['name'] }}. No audio or text generation.</p>
                    </div>
                </div>
                <div class="provider-result" @if ($configuration->connection_status === 'failed') role="alert" @endif>
                    <strong>{{ $configuration->statusLabel() }}</strong>
                    <p>{{ $configuration->last_message ?? 'No request has been made for this credential. Saving a key is not a successful connection test.' }}
                    </p>
                    <div class="provider-result-meta">
                        <span>Model selection:
                            {{ $configuration->selected_model ? (\App\Services\Providers\Catalog::validSelection($configuration->catalog ?? [], $capability, $configuration->selected_model, $configuration->selected_voice, $configuration->provider) ? 'catalog-compatible' : 'unavailable / incompatible') : 'not selected' }}</span>
                        <span>Backend resolution:
                            {{ $configuration->readyForResolution() ? 'eligible (not voice-tested)' : 'blocked' }}</span>
                        @if ($configuration->last_tested_at)
                            <span>Checked {{ $configuration->last_tested_at->format('Y-m-d H:i:s') }}
                            UTC</span>@else<span>Not tested yet</span>
                        @endif
                        @if ($configuration->last_http_status)
                            <span>HTTP {{ $configuration->last_http_status }}</span>
                        @endif
                        @if ($configuration->last_error_code)
                            <code>{{ $configuration->last_error_code }}</code>
                        @endif
                    </div>
                    @if ($configuration->last_success_at)
                        <small>Last successful authentication/catalog check for this credential:
                            {{ $configuration->last_success_at->format('Y-m-d H:i:s') }} UTC.</small>
                    @endif
                </div>
                @if ($configuration->connection_status === 'authenticated' && !$configuration->freshCatalog())
                    <p class="provider-notice">This snapshot is older than 24 hours. Recheck before selecting or resolving a
                        model.</p>
                @endif
                @foreach ($configuration->warnings ?? [] as $warning)
                    <p class="provider-notice">{{ $warning }}</p>
                @endforeach
                @if ($canManage)
                    <form method="POST" action="{{ route($routePrefix . '.test', $configuration->id) }}"
                        class="provider-test-form" x-data="{ pending: false }"
                        @submit="if (pending) { $event.preventDefault(); } else { pending = true; }">@csrf<input
                            type="hidden" name="revision" value="{{ $configuration->revision }}"><button type="submit"
                            class="button button--purple button--small" @disabled(!$configuration->hasCredential())
                            :disabled="pending || {{ $configuration->hasCredential() ? 'false' : 'true' }}"><span
                                x-text="pending ? 'Checking provider…' : 'Test Connection'">Test Connection</span><x-icon
                                name="arrow-up-right" /></button><span class="field-help">Read-only · 25-second overall
                            budget · rate limited</span></form>
                @endif
                <p class="provider-fine-print">Authentication, catalog visibility, model selection, inference permissions,
                    and an end-to-end voice test are different checks. This screen performs only the first two. Provider
                    requests can count toward API rate limits.</p>
            </section>
            <section class="workspace-panel form-panel" aria-labelledby="provider-selection">
                <div class="form-section-heading"><span>02</span>
                    <div>
                        <h2 id="provider-selection">The right model. The right voice.</h2>
                        <p>Compatible choices from this credential’s last successful catalog check.</p>
                    </div>
                </div>
                @if ($configuration->freshCatalog() && count($configuration->catalog['models'] ?? []))
                    <form method="POST" action="{{ route($routePrefix . '.selection', $configuration->id) }}"
                        class="provider-selection" x-data="providerSelection">@csrf @method('PUT')<input type="hidden"
                            name="revision" value="{{ $configuration->revision }}">
                        <script type="application/json" data-provider-catalog>{!! \Illuminate\Support\Js::encode($configuration->catalog) !!}</script>
                        <fieldset @disabled(!$canManage)>
                            <div class="field"><label for="selected_model">{{ $capability->label() }} model</label><select
                                    id="selected_model" name="selected_model" required x-model="model"
                                    @change="modelChanged()">
                                    <option value="">Choose a discovered model</option>
                                    @foreach ($configuration->catalog['models'] as $model)
                                        <option value="{{ $model['id'] }}" @selected(old('selected_model', $configuration->selected_model) === $model['id'])>
                                            {{ $model['name'] }} —
                                            {{ $model['api'] }}{{ isset($model['shutdown_date']) ? ' · shutdown ' . $model['shutdown_date'] : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">Catalog presence does not prove an inference request will be
                                    permitted. Unknown/incompatible models are never silently substituted.</span>
                            </div>
                            @if ($capability->needsVoice())
                                <div class="field"><label for="selected_voice">Compatible voice</label><select
                                        id="selected_voice" name="selected_voice" required x-model="voice">
                                        <option value="">Choose a voice for this model</option>
                                        @foreach ($configuration->catalog['voices'] ?? [] as $voice)
                                            <option value="{{ $voice['id'] }}" @selected(old('selected_voice', $configuration->selected_voice) === $voice['id'])
                                                :hidden="!allows(@js($voice['id']))"
                                                :disabled="!allows(@js($voice['id']))">{{ $voice['name'] }} ·
                                                {{ $voice['id'] }}</option>
                                        @endforeach
                                    </select>
                                    <span
                                        class="field-help">{{ $configuration->provider === 'deepgram' ? 'Aura embeds the voice in the model ID; choose the matching voice.' : 'Voice availability is based on provider metadata or official built-in voice documentation, not a synthesis test.' }}</span>
                                </div>
                            @endif
                            <div class="field"><label for="routing_role">Routing preference</label><select
                                    id="routing_role" name="routing_role">
                                    @foreach (['none' => 'No default / fallback assignment', 'default' => 'Default for this category and scope', 'fallback' => 'Explicit fallback for this category and scope'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('routing_role', $configuration->routing_role) === $value)>
                                            {{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="field-help">One default and one fallback per category/scope. Assigning one here
                                    replaces the previous assignment. This does not activate an agent or implement live
                                    mid-call failover. A local fallback needs a local default; without one, only shared platform routing is considered.</span>
                            </div>
                        </fieldset>
                        @if ($canManage)
                            <button type="submit" class="button button--purple button--small">Save model & routing <x-icon
                                    name="check" /></button>
                        @endif
                    </form>
                    <details class="provider-catalog-details">
                        <summary>What the provider actually reported</summary>
                        <ul>
                            @foreach ($configuration->catalog['models'] as $model)
                                <li><strong>{{ $model['id'] }}</strong><span>{{ $model['source'] }}</span><span>Languages:
                                        {{ count($model['languages']) ? implode(', ', $model['languages']) : 'not reported in this catalog; consult model documentation' }}.
                                        Streaming:
                                        {{ ($model['streaming'] ?? null) === null ? 'not reported / not verified here' : ($model['streaming'] ? 'supported by metadata/docs, not exercised' : 'not supported by metadata') }}.</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @else
                    <div class="provider-selection-empty"><x-icon name="info" />
                        <p>{{ $configuration->freshCatalog() ? 'Authentication passed, but no documented compatible models were found for this category. No model is selectable and this configuration cannot resolve.' : 'Run a successful connection check before selecting a model or voice. Only a fresh catalog for the current credential is accepted.' }}
                        </p>
                    </div>
                @endif
                <p class="provider-fine-print">Voice engine readiness: <strong>not tested / not implemented in this
                        milestone.</strong> No model here turns a draft agent into an active caller.</p>
            </section>
            <section class="workspace-panel form-panel" aria-labelledby="provider-usage">
                <div class="form-section-heading"><span><x-icon name="clock" /></span>
                    <div>
                        <h2 id="provider-usage">Usage & allowance</h2>
                        <p>Provider-reported account information, never invented Voca activity.</p>
                    </div>
                </div>
                @if ($configuration->usage_snapshot)
                    <dl class="provider-usage-list">
                        @foreach ($configuration->usage_snapshot['items'] as $item)
                            <div>
                                <dt>{{ $item['label'] }}</dt>
                                <dd>{{ $item['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="provider-fine-print">Snapshot as of
                        {{ \Illuminate\Support\Carbon::parse($configuration->usage_snapshot['fetched_at'])->format('Y-m-d H:i:s') }}
                        UTC. Refresh with Test Connection. These are account totals/balances, not Voca-attributed
                        consumption or an invoice.</p>
                @else
                    <p class="provider-copy">
                        {{ $configuration->provider === 'openai' || $configuration->provider === 'gemini' ? 'Account usage is not fetched with this standard API-key integration. No organization-admin billing key or cloud-monitoring access is requested.' : 'No account usage/allowance snapshot is available. Test the connection and check the required read permissions; optional Deepgram balances may remain unavailable.' }}
                    </p>
                @endif
                <a href="{{ $definition['usage_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-link">View
                    actual usage in {{ $definition['name'] }} <x-icon name="arrow-up-right" /></a>
            </section>
            @if ($canManage)
                <section class="workspace-panel form-panel" aria-labelledby="secure-credentials">
                    <div class="form-section-heading"><span><x-icon name="shield" /></span>
                        <div>
                            <h2 id="secure-credentials">Secure credential management</h2>
                            <p>Provider and category stay fixed. Add a separate connection to switch providers.</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route($routePrefix . '.update', $configuration->id) }}"
                        class="provider-form">@csrf @method('PUT')<input type="hidden" name="revision"
                            value="{{ $configuration->revision }}">@include('workspace.providers.credential-fields')<div
                            class="provider-form-actions"><button type="submit"
                                class="button button--purple button--small">Save connection changes <x-icon
                                    name="shield" /></button></div>
                        <p class="provider-fine-print">Replacing a key or changing project/organization IDs clears the
                            catalog, model/voice selection, routing assignment, and current verification result. A new check
                            is required.</p>
                    </form>
                    <div class="provider-danger-actions">
                        @if ($configuration->hasCredential())
                            <form method="POST"
                                action="{{ route($routePrefix . '.credential.destroy', $configuration->id) }}"
                                data-confirm="Remove this stored key and disable its routing? This does not revoke the upstream API key.">
                                @csrf @method('DELETE')<input type="hidden" name="revision"
                                    value="{{ $configuration->revision }}"><button type="submit"
                                    class="provider-danger-button">Remove stored key</button></form>
                        @endif
                        <form method="POST" action="{{ route($routePrefix . '.destroy', $configuration->id) }}"
                            data-confirm="Delete this provider configuration, its key, and connection-test history? Revoke the upstream key in its provider console separately.">
                            @csrf @method('DELETE')<input type="hidden" name="revision"
                                value="{{ $configuration->revision }}"><button type="submit"
                                class="provider-danger-button">Delete connection</button></form>
                    </div>
                </section>
            @endif
            <section class="workspace-panel form-panel" aria-labelledby="connection-history">
                <div class="form-section-heading"><span><x-icon name="book" /></span>
                    <div>
                        <h2 id="connection-history">Connection check history</h2>
                        <p>Last 20 read-only checks. No request bodies, raw errors, or secrets are stored.</p>
                    </div>
                </div>
                <ol class="provider-history">
                    @forelse ($history as $entry)
                        <li>
                            <div>
                                <strong>{{ $entry->outcome === 'authenticated' ? 'Authentication / catalog passed' : 'Check failed' }}</strong><span>{{ $entry->created_at->format('Y-m-d H:i:s') }}
                                    UTC · key v{{ $entry->credential_version }} · {{ $entry->duration_ms }}
                                    ms{{ $entry->http_status ? ' · HTTP ' . $entry->http_status : ' · no HTTP response recorded' }}</span>
                            </div>
                            <p>{{ $entry->message }}</p><code>{{ $entry->code }}</code>
                            @if (!$entry->applied)
                                <small>Not applied: the configuration changed during this check.</small>
                            @elseif ($entry->credential_version !== $configuration->credential_version)
                                <small>Historical credential version. Not evidence for the current key.</small>
                            @endif
                        </li>
                    @empty<li>No checks have been run. No connection status has been assumed.</li>
                    @endforelse
                </ol>
            </section>
        </div>
        @include('workspace.providers.instructions')
    </div>
@endsection
