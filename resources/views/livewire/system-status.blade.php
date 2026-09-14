<div>
    <h1>Meta Style</h1>
    <p class="sub">Phase 1 — Laravel foundation. No business features yet.</p>

    <div class="card">
        <dl>
            <dt>Environment</dt>
            <dd>{{ app()->environment() }}</dd>

            <dt>Laravel</dt>
            <dd>{{ app()->version() }}</dd>

            <dt>PHP</dt>
            <dd>{{ PHP_VERSION }}</dd>

            <dt>Locale</dt>
            <dd>{{ $locale }} <span class="sub">({{ $direction }})</span></dd>

            @if ($expanded)
                <dt>Languages</dt>
                <dd>{{ implode(', ', $supported) }}</dd>

                <dt>Control DB</dt>
                <dd><code>{{ config('database.connections.control.database') }}</code></dd>

                <dt>Tenant DB</dt>
                <dd><code>{{ config('database.connections.tenant.database') ?? 'not initialised' }}</code></dd>
            @endif
        </dl>

        <p style="margin: 1.25rem 0 0;">
            <button type="button" wire:click="toggle">
                {{ $expanded ? 'Show less' : 'Show more' }}
            </button>
        </p>
    </div>
</div>
