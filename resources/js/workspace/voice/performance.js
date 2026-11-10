function finite(value) {
    return Number.isFinite(value) && value >= 0;
}

// Browser RTCStats, not metadata HTTP durations, provider inference or a human-ear measurement.
export function audioPerformance(reports, previous = null) {
    const result = {};
    let sender = null;
    for (const report of reports.filter(Boolean)) {
        report.forEach((row) => {
            if (
                row.type === 'candidate-pair' &&
                row.state === 'succeeded' &&
                (row.nominated || row.selected) &&
                finite(row.currentRoundTripTime)
            )
                result.rtt_ms = row.currentRoundTripTime * 1000;
            if (row.type === 'inbound-rtp' && (row.kind || row.mediaType) === 'audio') {
                if (finite(row.jitter)) result.jitter_ms = row.jitter * 1000;
                if (finite(row.packetsLost)) result.packets_lost = row.packetsLost;
                if (finite(row.bytesReceived)) result.bytes_received = row.bytesReceived;
                if (finite(row.jitterBufferDelay) && row.jitterBufferEmittedCount > 0)
                    result.mean_buffer_ms =
                        (row.jitterBufferDelay / row.jitterBufferEmittedCount) * 1000;
            }
            if (
                row.type === 'outbound-rtp' &&
                (row.kind || row.mediaType) === 'audio' &&
                finite(row.bytesSent) &&
                finite(row.timestamp)
            )
                sender = { bytes: row.bytesSent, timestamp: row.timestamp };
        });
    }
    if (
        sender &&
        previous &&
        sender.timestamp > previous.timestamp &&
        sender.bytes >= previous.bytes
    )
        result.uplink_kbps =
            ((sender.bytes - previous.bytes) * 8) / (sender.timestamp - previous.timestamp);
    return { values: result, sender };
}
