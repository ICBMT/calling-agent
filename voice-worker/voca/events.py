from __future__ import annotations

import asyncio
import json
import time
from collections.abc import Callable

from livekit import rtc

from .contracts import SessionGrant


class RoomEvents:
    """Small, room-only UI events. Transcripts never pass through PHP or a telemetry upload."""

    def __init__(self, room: rtc.Room, grant: SessionGrant, failed: Callable[[], None]):
        self.room = room
        self.grant = grant
        self.failed = failed
        self.queue: asyncio.Queue[bytes] = asyncio.Queue(maxsize=64)
        self.sequence = 0
        self.task: asyncio.Task | None = None

    def emit(self, kind: str, **data) -> None:
        self.sequence += 1
        packet = {
            "v": 1,
            "session_id": self.grant.session_id,
            "seq": self.sequence,
            "type": kind,
            "sent_at": time.time(),
            **data,
        }
        raw = json.dumps(
            packet, ensure_ascii=False, allow_nan=False, separators=(",", ":")
        ).encode()
        if len(raw) > 15360:
            self.failed()
            return
        if self.queue.full():
            # Do not grow memory on a stalled data channel. A sequence gap is observable in the UI.
            self.queue.get_nowait()
            self.queue.task_done()
        self.queue.put_nowait(raw)

    def start(self) -> None:
        self.task = asyncio.create_task(self._send())

    async def _send(self) -> None:
        try:
            while True:
                packet = await self.queue.get()
                try:
                    await asyncio.wait_for(
                        self.room.local_participant.publish_data(
                            packet,
                            reliable=True,
                            topic="voca.events",
                            destination_identities=[self.grant.participant_identity],
                        ),
                        timeout=3,
                    )
                finally:
                    self.queue.task_done()
        except asyncio.CancelledError:
            raise
        except Exception:
            self.failed()

    async def close(self) -> None:
        if self.task:
            self.task.cancel()
            await asyncio.gather(self.task, return_exceptions=True)
            self.task = None
        while not self.queue.empty():
            self.queue.get_nowait()
            self.queue.task_done()
