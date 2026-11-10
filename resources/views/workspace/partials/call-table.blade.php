<div class="table-scroll">
    <table class="workspace-table">
        <thead>
            <tr>
                <th>Call</th>
                <th>Agent</th>
                <th>Direction</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Started</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($calls as $call)
                <tr>
                    <td><span class="table-call-id">{{ mb_substr($call->public_id, 0, 8) }}</span></td>
                    <td>{{ $call->agent?->name ?? 'Unassigned' }}</td>
                    <td>{{ ucfirst($call->direction) }}</td>
                    <td><span class="status-tag">{{ ucfirst(str_replace('_', ' ', $call->status)) }}</span>
                    </td>
                    <td>{{ \App\Support\Duration::format($call->duration_seconds) }}</td>
                    <td>{{ ($call->started_at ?? $call->created_at)->timezone($workspace->timezone)->format('M j, H:i') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
