from __future__ import annotations

import os
import re
from dataclasses import dataclass, field
from urllib.parse import urlsplit

from .contracts import ContractError


@dataclass(frozen=True)
class Settings:
    origin: str
    key_id: str
    secret: str = field(repr=False)

    @classmethod
    def from_env(cls) -> Settings:
        origin = os.environ.get("VOCA_CONTROL_URL", "").rstrip("/")
        key_id = os.environ.get("VOICE_WORKER_KEY_ID", "v1")
        secret = os.environ.get("VOICE_WORKER_SECRET", "")
        try:
            parsed = urlsplit(origin)
            insecure_local = (
                os.environ.get("VOICE_ALLOW_INSECURE_LOCAL") == "true"
                and parsed.scheme == "http"
                and parsed.hostname in {"localhost", "127.0.0.1", "::1"}
            )
            if (
                (parsed.scheme != "https" and not insecure_local)
                or not parsed.hostname
                or parsed.username is not None
                or parsed.password is not None
                or parsed.path not in {"", "/"}
                or parsed.query
                or parsed.fragment
                or len(origin) > 2048
                or any(ord(char) <= 32 or ord(char) == 127 for char in origin)
                or not 32 <= len(secret) <= 256
                or secret == os.environ.get("LIVEKIT_API_SECRET")
                or not re.fullmatch(r"[A-Za-z0-9_-]{1,40}", key_id)
                or os.environ.get("OPENAI_API_VERSION")
                or os.environ.get("LK_OPENAI_DEBUG", "0") not in {"", "0"}
            ):
                raise ValueError
        except ValueError:
            raise ContractError("Invalid worker environment. See voice-worker/README.md.") from None
        return cls(origin=origin, key_id=key_id, secret=secret)
