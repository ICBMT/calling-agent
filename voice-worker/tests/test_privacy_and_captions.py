import asyncio
import json
import logging

import pytest

from livekit.agents.cli.log import JsonFormatter

from voca.captions import Captions, StreamRedactor
from voca.events import RoomEvents
from voca.privacy import install_safe_logging, safe_text


class Capture:
    def __init__(self):
        self.packets = []

    def emit(self, kind, **data):
        self.packets.append({"type": kind, **data})


def test_dependency_log_extras_messages_and_chains_are_suppressed_before_formatting():
    install_safe_logging()
    secret = "test-only-private-provider-key"
    try:
        raise RuntimeError(secret)
    except RuntimeError:
        import sys

        record = logging.getLogger("livekit.agents").makeRecord(
            "livekit.agents",
            logging.ERROR,
            __file__,
            1,
            "Raw secret %s",
            (secret,),
            sys.exc_info(),
            extra={"lk.pii.event": {"api_key": secret}, "request": secret, "body": secret},
        )
    rendered = JsonFormatter().format(record)
    assert secret not in rendered
    assert "Raw secret" not in rendered
    assert "lk.pii.event" not in rendered
    assert "RuntimeError" in rendered
    assert "exc_info" not in rendered


def test_only_allowlisted_operator_event_fields_survive():
    install_safe_logging()
    record = logging.getLogger("voca").makeRecord(
        "voca",
        logging.WARNING,
        __file__,
        1,
        "session.stopped",
        (),
        None,
        extra={
            "session_id": "11111111-1111-4111-8111-111111111111",
            "reason": "provider_auth",
            "api_key": "private",
        },
    )
    data = json.loads(JsonFormatter().format(record))
    assert data["message"] == "session.stopped"
    assert data["reason"] == "provider_auth"
    assert "api_key" not in data


@pytest.mark.parametrize(
    "chunks",
    [
        ["Before ", "test-only-secret", " after"],
        ["Before test-", "only-", "secret after"],
        list("Before test-only-secret after"),
    ],
)
def test_streaming_redactor_cannot_expose_a_credential_split_across_deltas(chunks):
    secret = "test-only-secret"
    redactor = StreamRedactor((secret,))
    snapshots = []
    text = ""
    for chunk in chunks:
        text += redactor.push(chunk)
        snapshots.append(text)
    text += redactor.finish()
    assert text == "Before [redacted] after"
    assert secret not in json.dumps(snapshots)
    assert "test-only-secre" not in json.dumps(snapshots)


def test_unicode_and_direction_are_preserved_but_controls_and_literal_keys_are_not():
    assert safe_text("اردو हिन्दी\nhello\x00secret\r", ("secret",)) == "اردو हिन्दी\nhello[redacted]"
    assert len(safe_text("x" * 10000)) == 3000


def test_captions_are_bounded_redacted_fresh_per_generation_and_only_a_preview():
    capture = Capture()
    captions = Captions(capture, ("test-only-secret",))

    async def exercise():
        await captions.capture_text("اردو test-only-")
        await captions.capture_text("secret جواب")
        captions.flush()
        await captions.capture_text("A second response")

    asyncio.run(exercise())
    assert capture.packets[0]["id"] == capture.packets[1]["id"]
    assert capture.packets[1]["text"] == "اردو [redacted] جواب"
    assert capture.packets[2]["final"] is True
    assert capture.packets[3]["id"] != capture.packets[0]["id"]
    assert "test-only-" not in json.dumps(capture.packets)


def test_room_output_queue_is_bounded_and_only_the_authorized_browser_is_addressed(grant):
    from types import SimpleNamespace
    from unittest.mock import AsyncMock

    participant = SimpleNamespace(publish_data=AsyncMock())
    room = SimpleNamespace(local_participant=participant)
    errors = []
    output = RoomEvents(room, grant, lambda: errors.append(True))
    for value in range(100):
        output.emit("metrics", values={"turns": value})
    assert output.queue.qsize() == 64

    async def exercise():
        output.start()
        await asyncio.wait_for(output.queue.join(), 1)
        await output.close()

    asyncio.run(exercise())
    assert participant.publish_data.await_count == 64
    packet = participant.publish_data.await_args_list[0]
    assert packet.kwargs["destination_identities"] == [grant.participant_identity]
    assert packet.kwargs["topic"] == "voca.events"
    assert (
        json.loads(packet.args[0])["seq"] == 37
    )  # A gap is observable, not silent transcript completeness.
    assert not errors
