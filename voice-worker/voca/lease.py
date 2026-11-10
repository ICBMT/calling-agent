from __future__ import annotations

import time
from collections.abc import Callable


class AuthorityLease:
    """Monotonic local limits cannot be extended by a backwards wall clock or a late reply."""

    def __init__(
        self,
        expires_at: int,
        seconds: int,
        *,
        clock: Callable[[], float] = time.monotonic,
        wall: Callable[[], float] = time.time,
    ):
        self.clock = clock
        self.seconds = min(30, seconds)
        self.deadline = clock() + max(0.0, min(900.0, expires_at - wall()))
        self.authorized_until = min(self.deadline, clock() + self.seconds)

    def reason(self) -> str | None:
        if self.clock() >= self.deadline:
            return "session_expired"
        if self.clock() >= self.authorized_until:
            return "worker_timeout"
        return None

    def renew(self) -> bool:
        if self.reason():
            return False
        self.authorized_until = min(self.deadline, self.clock() + self.seconds)
        return True
