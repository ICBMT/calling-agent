from __future__ import annotations

import math
from collections.abc import Callable

from livekit.agents import llm
from livekit.agents.metrics import RealtimeModelMetrics


class Measurements:
    """Actual SDK values only. Missing latency remains absent, not zero or an invented SLA."""

    def __init__(self, changed: Callable[[dict], None] | None = None):
        self.values: dict[str, int] = {}
        self.changed = changed

    def _milliseconds(self, name: str, value: object) -> None:
        if type(value) in (int, float) and math.isfinite(value) and 0 <= value <= 120:
            self.values[name] = round(value * 1000)

    def realtime(self, event: object) -> None:
        if isinstance(event, RealtimeModelMetrics):
            self._milliseconds("provider_first_audio_ms", event.ttft)
            self.notify()

    def conversation(self, item: llm.ChatMessage) -> None:
        if item.role == "assistant":
            self.values["turns"] = self.values.get("turns", 0) + 1
            if item.interrupted:
                self.values["interruptions"] = self.values.get("interruptions", 0) + 1
            self._milliseconds("worker_turn_ms", item.metrics.get("e2e_latency"))
            self.notify()

    def usage(self, usage: object, model: str) -> None:
        # Session usage is cumulative, not a per-event delta. Never add cumulative snapshots together.
        matched = [
            row
            for row in getattr(usage, "model_usage", [])
            if row.type == "llm_usage" and row.model == model
        ]
        if matched:
            for key in (
                "input_tokens",
                "output_tokens",
                "input_audio_tokens",
                "output_audio_tokens",
            ):
                total = sum(getattr(row, key, 0) for row in matched)
                if type(total) is int and 0 <= total <= 100000000:
                    self.values[key] = total
            self.notify()

    def snapshot(self) -> dict[str, int]:
        return dict(self.values)

    def notify(self) -> None:
        if self.changed:
            self.changed(self.snapshot())
