from __future__ import annotations

from uuid import uuid4

from livekit.agents import io

from .events import RoomEvents
from .privacy import safe_text


class StreamRedactor:
    """Hold a possible secret prefix across provider chunks; never emit a key split into deltas."""

    def __init__(self, secrets: tuple[str, ...]):
        self.secrets = tuple(value for value in secrets if value)
        self.pending = ""

    def push(self, delta: str) -> str:
        value = self.pending + delta
        for secret in self.secrets:
            value = value.replace(secret, "[redacted]")
        held = 0
        for secret in self.secrets:
            for count in range(min(len(secret) - 1, len(value)), held, -1):
                if value.endswith(secret[:count]):
                    held = count
                    break
        self.pending = value[-held:] if held else ""
        return value[:-held] if held else value

    def finish(self) -> str:
        # A dangling credential prefix is safer to suppress than expose on cancellation.
        value = "[redacted]" if self.pending else ""
        self.pending = ""
        return value


class Captions(io.TextOutput):
    """Streamed generated text preview; authoritative final history comes from SDK ChatMessages.

    Default RoomIO text publishing is disabled so it cannot bypass this redaction boundary.
    Captions can precede playback; they are not claimed to be word-aligned or already heard.
    """

    def __init__(self, events: RoomEvents, secrets: tuple[str, ...]):
        super().__init__(label="voca-sanitized-captions", next_in_chain=None)
        self.events, self.secrets = events, secrets
        self._reset()

    def _reset(self) -> None:
        self.id = str(uuid4())
        self.text = ""
        self.redactor = StreamRedactor(self.secrets)

    async def capture_text(self, text: str) -> None:
        if len(self.text) >= 3000:
            return
        self.text += safe_text(self.redactor.push(text[:16384]), self.secrets)
        self.text = self.text[:3000]
        self.events.emit("assistant_partial", id=self.id, text=self.text, final=False)

    def flush(self) -> None:
        if self.text or self.redactor.pending:
            self.text = (self.text + self.redactor.finish())[:3000]
            self.events.emit("assistant_partial", id=self.id, text=self.text, final=True)
        self._reset()
