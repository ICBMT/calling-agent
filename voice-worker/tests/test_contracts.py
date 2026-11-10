import json
import pickle

import pytest

from voca.contracts import ContractError, Dispatch, SessionGrant, load_object
from voca.settings import Settings


def test_dispatch_is_canonical_bounded_room_bound_and_secret_hidden(dispatch, dispatch_data):
    assert dispatch.room == dispatch_data["room"]
    assert dispatch_data["ticket"] not in repr(dispatch)
    assert dispatch_data["ticket"] not in dispatch.model_dump_json()
    with pytest.raises(TypeError):
        pickle.dumps(dispatch)


@pytest.mark.parametrize(
    "mutation",
    [
        {"room": "another-room"},
        {"session_id": "../../secret"},
        {"v": 2},
        {"ticket": "secret-with-invalid-shape"},
        {"api_key": "injected-secret"},
    ],
)
def test_bad_dispatch_never_echoes_input(dispatch_data, mutation):
    dispatch_data.update(mutation)
    with pytest.raises(ContractError) as error:
        Dispatch.parse(json.dumps(dispatch_data), dispatch_data["room"])
    assert "secret" not in str(error.value)


@pytest.mark.parametrize("raw", ["[]", '{"v":1,"v":1}', '"secret"', "x" * 65537, b"\xff"])
def test_json_shape_duplicate_keys_size_and_encoding(raw):
    with pytest.raises(ContractError):
        load_object(raw)


def test_valid_grant_is_private_redacted_and_expiring(grant, grant_data):
    assert grant.configuration.architecture == "native_realtime"
    assert grant_data["credentials"]["api_key"] not in repr(grant)
    assert grant_data["credentials"]["api_key"] not in grant.model_dump_json()
    assert grant.configuration.instructions not in repr(grant)
    with pytest.raises(TypeError):
        pickle.dumps(grant)


@pytest.mark.parametrize(
    "field,value",
    [
        ("room", "other-room"),
        ("session_id", "22222222-2222-4222-8222-222222222222"),
        ("participant_identity", "a-different-browser"),
        ("worker_identity", "a-different-agent"),
        ("lease", "not-a-valid-lease"),
        ("expires_at", 1),
        ("expires_at", 9999999999),
        ("heartbeat_seconds", 60),
        ("lease_seconds", 20),
        ("unexpected", "private"),
    ],
)
def test_bad_grant_binding_lifetimes_and_schema(dispatch, grant_data, field, value):
    grant_data[field] = value
    with pytest.raises(ContractError):
        SessionGrant.parse(grant_data, dispatch)


@pytest.mark.parametrize(
    "field,value",
    [
        ("provider", "gemini"),
        ("architecture", "modular"),
        ("model", "gpt-4o-mini-tts"),
        ("voice", "fable"),
        ("language", "zz"),
        ("transcription_model", "undocumented-model"),
        ("instructions", "too short"),
        ("greeting", "x" * 501),
    ],
)
def test_only_implemented_native_capabilities_accept(dispatch, grant_data, field, value):
    grant_data["configuration"][field] = value
    with pytest.raises(ContractError):
        SessionGrant.parse(grant_data, dispatch)


@pytest.mark.parametrize(
    "field,value",
    [
        ("project_id", "proj\r\nInjected: secret"),
        ("organization", "x y"),
        ("api_key", "short"),
        ("api_key", "test-only\nsecret"),
    ],
)
def test_server_credentials_cannot_inject_http_headers(dispatch, grant_data, field, value):
    grant_data["credentials"][field] = value
    with pytest.raises(ContractError) as error:
        SessionGrant.parse(grant_data, dispatch)
    assert value not in str(error.value)


@pytest.mark.parametrize(
    "url",
    [
        "http://public.example.test",
        "https://u:secret@example.test",
        "https://example.test/path",
        "https://example.test?key=secret",
        "https://example.test#fragment",
        "https://example.test\n",
    ],
)
def test_worker_control_origin_is_operator_only_https_without_paths_or_credentials(
    monkeypatch, url
):
    monkeypatch.setenv("VOCA_CONTROL_URL", url)
    monkeypatch.setenv("VOICE_WORKER_SECRET", "test-only-worker-secret-" + "w" * 32)
    with pytest.raises(ContractError):
        Settings.from_env()


def test_local_http_requires_explicit_opt_in_and_non_loopback_is_still_rejected(monkeypatch):
    monkeypatch.setenv("VOCA_CONTROL_URL", "http://127.0.0.1:8000")
    monkeypatch.setenv("VOICE_WORKER_SECRET", "test-only-worker-secret-" + "w" * 32)
    with pytest.raises(ContractError):
        Settings.from_env()
    monkeypatch.setenv("VOICE_ALLOW_INSECURE_LOCAL", "true")
    assert Settings.from_env().origin == "http://127.0.0.1:8000"
    monkeypatch.setenv("VOCA_CONTROL_URL", "http://public.example.test")
    with pytest.raises(ContractError):
        Settings.from_env()


def test_global_debug_azure_override_or_shared_worker_livekit_secret_are_rejected(monkeypatch):
    monkeypatch.setenv("VOCA_CONTROL_URL", "https://control.example.test")
    secret = "test-only-worker-secret-" + "w" * 32
    monkeypatch.setenv("VOICE_WORKER_SECRET", secret)
    assert secret not in repr(Settings.from_env())
    monkeypatch.setenv("LK_OPENAI_DEBUG", "1")
    with pytest.raises(ContractError):
        Settings.from_env()
    monkeypatch.delenv("LK_OPENAI_DEBUG")
    monkeypatch.setenv("OPENAI_API_VERSION", "override")
    with pytest.raises(ContractError):
        Settings.from_env()
    monkeypatch.delenv("OPENAI_API_VERSION")
    monkeypatch.setenv("LIVEKIT_API_SECRET", secret)
    with pytest.raises(ContractError):
        Settings.from_env()
