<div class="field"><label for="name">Connection name</label><input id="name" name="name" required
        maxlength="100"
        value="{{ old('name', isset($configuration) ? $configuration->name : $definition['name'] . ' connection') }}"
        autocomplete="off"><span class="field-help">A friendly name. Never put a credential in this field.</span></div>
<div class="field"><label for="api_key">{{ isset($configuration) ? 'Replace API key' : 'API key' }}</label><input
        type="password" id="api_key" name="api_key" minlength="8" maxlength="2048" autocomplete="new-password"
        autocapitalize="off" spellcheck="false" @required(!isset($configuration)) aria-describedby="key-help"><span class="field-help"
        id="key-help">{{ isset($configuration) && $configuration->hasCredential() ? 'Stored securely: ••••••••. Leave blank to keep it. The existing value is never returned to this page.' : 'Encrypted on save. The stored key is never returned to this page or included in validation old input.' }}</span>
</div>
@if (($provider ?? $configuration->provider) === 'deepgram')
    <div class="field"><label for="project_id">Deepgram project UUID <span>optional</span></label><input id="project_id" name="project_id"
            maxlength="64" value="{{ old('project_id', $configuration->project_id ?? '') }}"
            autocomplete="off"><span class="field-help">Optional project identifier (e.g. proj_... or UUID), copied from the Deepgram
            console.</span></div>
@elseif (($provider ?? $configuration->provider) === 'openai')
    <div class="form-row">
        <div class="field"><label for="project_id">OpenAI project ID <span>optional</span></label><input
                id="project_id" name="project_id" maxlength="100"
                value="{{ old('project_id', $configuration->project_id ?? '') }}" autocomplete="off"></div>
        <div class="field"><label for="provider_organization">OpenAI organization ID
                <span>optional</span></label><input id="provider_organization" name="provider_organization"
                maxlength="100" value="{{ old('provider_organization', $configuration->provider_organization ?? '') }}"
                autocomplete="off"></div>
    </div>
@endif
<input type="hidden" name="enabled" value="0">
<label class="provider-checkbox"><input type="checkbox" name="enabled" value="1"
        @checked((bool) old('enabled', $configuration->enabled ?? true))><span><strong>Enable this configuration</strong><small>Untested or incompatible
            configurations cannot resolve as a default.</small></span></label>
