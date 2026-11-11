@extends('layouts.workspace')
@section('page-title', 'Call history')
@section('content')
    <div class="workspace-page-heading">
        <div><span class="eyebrow">THE REAL CONVERSATION.</span>
            <h1>Every hello has a story<span class="grape-text">.</span></h1>
            <p>Your actual call records. Nothing invented. Nothing simulated.</p>
        </div>
    </div>
    <form method="GET" action="{{ route('workspace.calls') }}" class="call-filters">
        <div class="field"><label for="direction">Direction</label><select id="direction" name="direction">
                <option value="">All directions</option>
                @foreach (['incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'browser' => 'Browser'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['direction'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label for="call-status">Call status</label><select id="call-status" name="status">
                <option value="">All statuses</option>
                @foreach (['queued', 'ringing', 'in_progress', 'completed', 'failed', 'missed', 'canceled'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                        {{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
        </div><button type="submit" class="button button--dark button--small">Apply filters <x-icon
                name="arrow-right" /></button>
        @if (array_filter($filters))
            <a href="{{ route('workspace.calls') }}" class="inline-link">Clear filters</a>
        @endif
    </form>
    <section class="workspace-panel">
        @if ($calls->isEmpty())
            <div class="empty-state">
                <div class="empty-icon"><x-icon name="phone" /></div>
                <h2>{{ array_filter($filters) ? 'No calls on this frequency.' : 'The next great conversation is ahead.' }}
                </h2>
                <p>{{ array_filter($filters) ? 'No call records match these filters. Try another direction or status.' : 'Call records will arrive with the real voice and telephony integrations. This workspace has no call activity yet.' }}
                </p><a href="{{ route('workspace.setup') }}" class="button button--purple button--small">See the
                    setup journey <x-icon /></a>
            </div>
        @else
            @include('workspace.partials.call-table')
        @endif
    </section>
    <div class="pagination-wrap">{{ $calls->links() }}</div>
@endsection
