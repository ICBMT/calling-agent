<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Providers\ProviderCredentialRequest;
use App\Http\Requests\Providers\ProviderSelectionRequest;
use App\Models\ProviderConfiguration;
use App\Services\Providers\Capability;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderConnectionTester;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function index(Request $request, ProviderRegistry $registry): View
    {
        return view('workspace.providers.index', [
            ...$this->context($request), 'definitions' => $registry->definitions(),
            'configurations' => ProviderConfiguration::query()->forScope($request->attributes->get('provider_scope_organization'))->latest()->paginate(12),
        ]);
    }

    public function compare(Request $request, ProviderRegistry $registry): View
    {
        return view('workspace.providers.compare', [...$this->context($request), 'definitions' => $registry->definitions()]);
    }

    public function create(Request $request, ProviderRegistry $registry): View
    {
        $this->authorizeScope($request);
        $data = $request->validate(['provider' => ['required', Rule::in(array_keys($registry->definitions()))]]);
        $definition = $registry->definition($data['provider']);

        return view('workspace.providers.create', [
            ...$this->context($request), 'provider' => $data['provider'], 'definition' => $definition,
            'capabilities' => array_map(fn ($value) => Capability::from($value), $definition['capabilities']),
        ]);
    }

    public function store(ProviderCredentialRequest $request, ProviderConfigurationStore $store): RedirectResponse
    {
        $record = $store->create($request->attributes->get('provider_scope_organization'), $request->user(), $request->validated());

        return redirect()->route($this->context($request)['routePrefix'].'.edit', $record->id)
            ->with('status', 'Credential encrypted and saved. It has not been tested.');
    }

    public function edit(Request $request, string $configuration, ProviderRegistry $registry): View
    {
        $record = $this->find($request, $configuration);
        Gate::authorize('view', $record);

        return view('workspace.providers.edit', [
            ...$this->context($request), 'configuration' => $record,
            'definition' => $registry->definition($record->provider), 'capability' => Capability::from($record->capability),
            'history' => $record->connectionTests()->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(ProviderCredentialRequest $request, string $configuration, ProviderConfigurationStore $store): RedirectResponse
    {
        $store->update($this->find($request, $configuration), $request->user(), $request->validated());

        return back()->with('status', 'Configuration saved. Changed credentials require a new connection check.');
    }

    public function selection(ProviderSelectionRequest $request, string $configuration, ProviderConfigurationStore $store): RedirectResponse
    {
        $store->selection($this->find($request, $configuration), $request->user(), $request->validated());

        return back()->with('status', 'Compatible model, voice, and routing preference saved. No audio or call was generated.');
    }

    public function test(Request $request, string $configuration, ProviderConnectionTester $tester): RedirectResponse
    {
        $record = $this->find($request, $configuration);
        Gate::authorize('manage', $record);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $result = $tester->test($record, $request->user(), (int) $revision);

        return redirect()->route($this->context($request)['routePrefix'].($result ? '.edit' : '.index'), $result ? [$record->id] : [])
            ->with('status', ! $result || ! $result->applied
                ? 'The configuration or your access changed, or it was deleted during the request. This test did not update its status.'
                : ($result->outcome === 'authenticated' ? 'Read-only authentication/catalog check passed. This is not a voice test.' : 'Connection check failed. The real failure category and HTTP status (when received) are shown below.'))
            ->with('status_type', $result && $result->applied && $result->outcome === 'failed' ? 'error' : 'notice');
    }

    public function removeCredential(Request $request, string $configuration, ProviderConfigurationStore $store): RedirectResponse
    {
        $record = $this->find($request, $configuration);
        Gate::authorize('manage', $record);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $store->removeCredential($record, $request->user(), (int) $revision);

        return back()->with('status', 'Stored credential removed and routing disabled. Revoke the original key in the provider console separately.');
    }

    public function destroy(Request $request, string $configuration, ProviderConfigurationStore $store): RedirectResponse
    {
        $record = $this->find($request, $configuration);
        Gate::authorize('manage', $record);
        $revision = $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
        $store->delete($record, $request->user(), (int) $revision);

        return redirect()->route($this->context($request)['routePrefix'].'.index')->with('status', 'Provider configuration and its stored credential removed. The upstream API key was not revoked.');
    }

    private function find(Request $request, string $id): ProviderConfiguration
    {
        return ProviderConfiguration::query()->forScope($request->attributes->get('provider_scope_organization'))->findOrFail($id);
    }

    private function authorizeScope(Request $request): void
    {
        if (! $request->attributes->get('provider_platform_scope')) {
            Gate::authorize('manage', $request->attributes->get('organization'));
        }
    }

    private function context(Request $request): array
    {
        $platform = (bool) $request->attributes->get('provider_platform_scope');

        return [
            'platformScope' => $platform,
            'canManage' => $platform || $request->user()->can('manage', $request->attributes->get('organization')),
            'routePrefix' => $platform ? 'workspace.platform.providers' : 'workspace.providers',
        ];
    }
}
