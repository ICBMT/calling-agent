<aside class="workspace-panel provider-instructions">
    <span class="provider-monogram" aria-hidden="true">{{ $definition['initials'] }}</span>
    <h2>A little setup.<br>A secure beginning.</h2>
    <ol>
        @foreach ($definition['instructions'] as $instruction)
            <li>{{ $instruction }}</li>
        @endforeach
    </ol>
    <a href="{{ $definition['console'] }}" target="_blank" rel="noopener noreferrer" class="inline-link">Open
        {{ $definition['name'] }} console <x-icon name="arrow-up-right" /></a>
    <a href="{{ $definition['docs'] }}" target="_blank" rel="noopener noreferrer" class="inline-link">Official setup
        documentation <x-icon name="book" /></a>
    <div class="provider-endpoint"><span>Trusted API endpoint ·
            read-only</span><code>{{ $definition['endpoint'] }}</code></div>
    <p class="provider-fine-print">Documentation reviewed {{ config('providers.reviewed_on') }}. Actual access depends
        on your account, scopes, region, and provider changes. Custom endpoints and redirects are blocked to protect
        keys.</p>
</aside>
