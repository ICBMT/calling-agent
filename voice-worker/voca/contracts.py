from __future__ import annotations

import json
import re
import time
from typing import Literal
from uuid import UUID

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    SecretStr,
    ValidationError,
    field_validator,
    model_validator,
)

MODELS = frozenset(
    {
        "gpt-realtime-2.1",
        "gpt-realtime-2.1-mini",
        "gpt-realtime-2",
        "gpt-realtime-1.5",
        "gpt-realtime",
    }
)
GEMINI_MODELS = frozenset(
    {
        "gemini-3.8-live",
    }
)
VOICES = frozenset(
    {"alloy", "ash", "ballad", "coral", "echo", "sage", "shimmer", "verse", "marin", "cedar"}
)
GEMINI_VOICES = frozenset(
    {
        "Achernar",
        "Achird",
        "Algenib",
        "Algieba",
        "Alnilam",
        "Aoede",
        "Autonoe",
        "Callirrhoe",
        "Charon",
        "Despina",
        "Enceladus",
        "Erinome",
        "Fenrir",
        "Gacrux",
        "Iapetus",
        "Kore",
        "Laomedeia",
        "Leda",
        "Orus",
        "Pulcherrima",
        "Puck",
        "Rasalgethi",
        "Sadachbia",
        "Sadaltager",
        "Schedar",
        "Sulafat",
        "Umbriel",
        "Vindemiatrix",
        "Zephyr",
        "Zubenelgenubi",
    }
)
LANGUAGES = {"en": "English", "ur": "Urdu", "hi": "Hindi"}
ERROR_CODES = frozenset(
    {
        "provider_auth",
        "provider_quota",
        "provider_timeout",
        "provider_error",
        "participant_timeout",
        "browser_disconnected",
        "worker_timeout",
        "session_expired",
        "access_revoked",
        "session_ended",
    }
)


class ContractError(Exception):
    """Safe category only. Never wrap/log the payload or Pydantic's input-bearing diagnostics."""


def _object(pairs: list[tuple[str, object]]) -> dict:
    result: dict = {}
    for key, value in pairs:
        if key in result:
            raise ContractError("Invalid control-plane contract.")
        result[key] = value
    return result


def load_object(raw: str | bytes, maximum: int = 65536) -> dict:
    if len(raw) > maximum:
        raise ContractError("Invalid control-plane contract.")
    try:
        value = json.loads(raw, object_pairs_hook=_object)
        if not isinstance(value, dict):
            raise ValueError
        return value
    except (ValueError, TypeError, UnicodeError):
        raise ContractError("Invalid control-plane contract.") from None


class Contract(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True, hide_input_in_errors=True)

    def __reduce__(self):
        raise TypeError("Session contracts may not be pickled into jobs or caches.")


class Dispatch(Contract):
    v: Literal[1]
    session_id: str
    room: str
    ticket: SecretStr = Field(repr=False)

    @field_validator("session_id")
    @classmethod
    def canonical_uuid(cls, value: str) -> str:
        if str(UUID(value)) != value:
            raise ValueError("Invalid session id.")
        return value

    @field_validator("ticket")
    @classmethod
    def ticket_shape(cls, value: SecretStr) -> SecretStr:
        if not re.fullmatch(r"[a-f0-9]{64}", value.get_secret_value()):
            raise ValueError("Invalid claim ticket.")
        return value

    @classmethod
    def parse(cls, raw: str, actual_room: str) -> Dispatch:
        try:
            result = cls.model_validate(load_object(raw, 2048))
            if result.room != actual_room or result.room != f"voca-{result.session_id}":
                raise ValueError
            return result
        except (ValidationError, ValueError, TypeError, ContractError):
            raise ContractError("Invalid dispatch contract.") from None


class NativeConfiguration(Contract):
    v: Literal[1]
    provider: Literal["openai", "gemini"]
    architecture: Literal["native_realtime"]
    model: str
    voice: str
    language: Literal["en", "ur", "hi"]
    transcription_model: Literal["gpt-transcribe", "gemini-transcribe"]
    instructions: str = Field(min_length=20, max_length=12000, repr=False)
    greeting: str = Field(max_length=500, repr=False)
    personality: Literal["friendly", "professional", "empathetic", "concise"]

    @model_validator(mode="after")
    def validate_provider_model_and_voice(self) -> NativeConfiguration:
        if self.provider == "gemini":
            if self.model not in GEMINI_MODELS:
                raise ValueError("Unimplemented Gemini native model.")
            if self.voice not in GEMINI_VOICES:
                raise ValueError("Incompatible Gemini realtime voice.")
            if self.transcription_model != "gemini-transcribe":
                raise ValueError("Incompatible transcription model for Gemini.")
        else:
            if self.model not in MODELS:
                raise ValueError("Unimplemented native model.")
            if self.voice not in VOICES:
                raise ValueError("Incompatible realtime voice.")
            if self.transcription_model != "gpt-transcribe":
                raise ValueError("Incompatible transcription model for OpenAI.")
        return self


class Credentials(Contract):
    api_key: SecretStr = Field(repr=False)
    project_id: str | None = None
    organization: str | None = None

    @field_validator("api_key")
    @classmethod
    def safe_key(cls, value: SecretStr) -> SecretStr:
        key = value.get_secret_value()
        if not 8 <= len(key) <= 4096 or any(ord(char) < 33 or ord(char) == 127 for char in key):
            raise ValueError("Invalid credential.")
        return value

    @field_validator("project_id", "organization")
    @classmethod
    def safe_header(cls, value: str | None) -> str | None:
        if value is not None and not re.fullmatch(r"[A-Za-z0-9_.:-]{1,100}", value):
            raise ValueError("Invalid project or organization header.")
        return value


class SessionGrant(Contract):
    v: Literal[1]
    session_id: str
    room: str
    participant_identity: str
    worker_identity: str
    lease: SecretStr = Field(repr=False)
    expires_at: int
    heartbeat_seconds: int = Field(ge=5, le=10)
    lease_seconds: int = Field(ge=20, le=30)
    configuration: NativeConfiguration = Field(repr=False)
    credentials: Credentials = Field(repr=False)

    @classmethod
    def parse(cls, payload: dict, dispatch: Dispatch, *, now: float | None = None) -> SessionGrant:
        try:
            result = cls.model_validate(payload)
            if (
                result.session_id != dispatch.session_id
                or result.room != dispatch.room
                or result.participant_identity != f"voca-user-{dispatch.session_id}"
                or result.worker_identity != f"voca-agent-{dispatch.session_id}"
                or not re.fullmatch(r"[a-f0-9]{64}", result.lease.get_secret_value())
                or not 0 < result.expires_at - (now if now is not None else time.time()) <= 905
                or result.lease_seconds <= result.heartbeat_seconds * 2
            ):
                raise ValueError
            return result
        except (ValidationError, ValueError, TypeError):
            raise ContractError("Invalid session grant.") from None
