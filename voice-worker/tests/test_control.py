import asyncio
import hashlib
import hmac
import json

import pytest

from voca.control import ControlClient, ControlError, signed_headers
from voca.settings import Settings


class Content:
    def __init__(self, raw):
        self.raw = raw

    async def iter_chunked(self, _size):
        # Real responses may arrive fragmented. A single StreamReader.read(n) is not read-all.
        for position in range(0, len(self.raw), 7):
            yield self.raw[position : position + 7]


class Response:
    def __init__(self, raw, status=200):
        self.content, self.status = Content(raw), status

    async def __aenter__(self):
        return self

    async def __aexit__(self, *_args):
        pass


class Http:
    def __init__(self, response):
        self.response = response
        self.requests = []

    def post(self, url, **options):
        self.requests.append((url, options))
        return self.response


def settings():
    return Settings("https://control.example.test", "v1", "test-only-worker-secret-" + "w" * 32)


def test_hmac_exact_bytes_and_canonical_contract_matches_laravel():
    body = '{"utterance":"اردو हिन्दी"}'.encode()
    path = "/internal/voice/sessions/11111111-1111-4111-8111-111111111111/claim"
    headers = signed_headers(settings(), path, body, timestamp=1794268800, nonce="a" * 32)
    canonical = "\n".join(
        ["v1", "v1", "POST", path, "1794268800", "a" * 32, hashlib.sha256(body).hexdigest()]
    )
    assert (
        headers["X-Voca-Signature"]
        == hmac.new(settings().secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    )
    assert settings().secret not in json.dumps(headers)


def test_real_contract_parse_and_one_claim_request_not_an_inference_call(dispatch, grant_data):
    http = Http(Response(json.dumps(grant_data).encode()))
    control = ControlClient(settings(), http)
    grant = asyncio.run(control.claim(dispatch, "AJ_test"))
    assert grant.credentials.api_key.get_secret_value() == grant_data["credentials"]["api_key"]
    assert len(http.requests) == 1
    url, options = http.requests[0]
    assert url.endswith(f"/{dispatch.session_id}/claim")
    assert options["allow_redirects"] is False
    assert options["timeout"].total == 6
    assert json.loads(options["data"]) == {
        "room": dispatch.room,
        "ticket": "a" * 64,
        "job_id": "AJ_test",
    }
    assert grant_data["credentials"]["api_key"] not in str(options["headers"])


@pytest.mark.parametrize("status", [301, 302, 401, 403, 429, 500])
def test_remote_errors_never_echo_bodies_or_follow_redirects(dispatch, status):
    private = b'{"message":"reflected-private-provider-key"}'
    http = Http(Response(private, status))
    with pytest.raises(ControlError) as error:
        asyncio.run(ControlClient(settings(), http).claim(dispatch, "AJ_test"))
    assert "reflected-private" not in str(error.value)
    assert error.value.status == status
    assert len(http.requests) == 1


@pytest.mark.parametrize(
    "body", [b"[]", b"not JSON", b"x" * 65537, b'{"continue":true,"continue":false}']
)
def test_bounded_response_shapes_never_count_as_success(dispatch, body):
    with pytest.raises(ControlError):
        asyncio.run(ControlClient(settings(), Http(Response(body))).claim(dispatch, "AJ_test"))


def test_sequence_numbers_never_reuse_after_failure_and_usage_is_aggregate(grant):
    response = json.dumps(
        {"continue": True, "state": "active", "error_code": None, "expires_at": grant.expires_at}
    ).encode()
    http = Http(Response(response))
    control = ControlClient(settings(), http)

    async def exercise():
        await control.heartbeat(grant, "AJ_test", "active", {"turns": 1})
        await control.heartbeat(grant, "AJ_test", "active", {"turns": 2})

    asyncio.run(exercise())
    first, second = [json.loads(options["data"]) for _, options in http.requests]
    assert first["sequence"] == 1
    assert second["sequence"] == 2
    assert second["metrics"] == {"turns": 2}
    assert first["lease"] == grant.lease.get_secret_value()
    assert (
        http.requests[0][1]["headers"]["X-Voca-Nonce"]
        != http.requests[1][1]["headers"]["X-Voca-Nonce"]
    )


@pytest.mark.parametrize(
    "overrides",
    [
        {"continue": "yes"},
        {"state": "revoked"},
        {"expires_at": 1},
        {"error_code": "private-key-reflection"},
    ],
)
def test_invalid_authority_reply_cannot_renew_a_lease(grant, overrides):
    response = {
        "continue": True,
        "state": "active",
        "error_code": None,
        "expires_at": grant.expires_at,
        **overrides,
    }
    with pytest.raises(ControlError):
        asyncio.run(
            ControlClient(settings(), Http(Response(json.dumps(response).encode()))).heartbeat(
                grant, "AJ_test", "active", {}
            )
        )
