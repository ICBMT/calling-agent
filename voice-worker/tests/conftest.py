import json
import time

import pytest

from voca.contracts import Dispatch, SessionGrant


@pytest.fixture
def dispatch_data():
    return {
        "v": 1,
        "session_id": "11111111-1111-4111-8111-111111111111",
        "room": "voca-11111111-1111-4111-8111-111111111111",
        "ticket": "a" * 64,
    }


@pytest.fixture
def dispatch(dispatch_data):
    return Dispatch.parse(json.dumps(dispatch_data), dispatch_data["room"])


@pytest.fixture
def grant_data(dispatch_data):
    return {
        "v": 1,
        "session_id": dispatch_data["session_id"],
        "room": dispatch_data["room"],
        "participant_identity": f"voca-user-{dispatch_data['session_id']}",
        "worker_identity": f"voca-agent-{dispatch_data['session_id']}",
        "lease": "b" * 64,
        "expires_at": int(time.time()) + 600,
        "heartbeat_seconds": 10,
        "lease_seconds": 25,
        "configuration": {
            "v": 1,
            "provider": "openai",
            "architecture": "native_realtime",
            "model": "gpt-realtime-2.1",
            "voice": "marin",
            "language": "en",
            "transcription_model": "gpt-transcribe",
            "instructions": "Help with verified information. Never invent completed actions.",
            "greeting": "Hello, I am an AI assistant.",
            "personality": "friendly",
        },
        "credentials": {
            "api_key": "test-only-provider-key-never-live",
            "project_id": "proj_test",
            "organization": "org_test",
        },
    }


@pytest.fixture
def grant(grant_data, dispatch):
    return SessionGrant.parse(grant_data, dispatch)


@pytest.fixture(autouse=True)
def no_global_provider_overrides(monkeypatch):
    for variable in ("OPENAI_API_KEY", "OPENAI_API_VERSION", "OPENAI_BASE_URL", "LK_OPENAI_DEBUG"):
        monkeypatch.delenv(variable, raising=False)
