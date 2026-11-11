import asyncio
import json
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from livekit import rtc
from livekit.agents import llm

from voca.runtime import VoiceRuntime, provider_reason
from voca.settings import Settings


class Session(rtc.EventEmitter):
    def __init__(self):
        super().__init__()
        self.output = SimpleNamespace(transcription=None)
        self.start = AsyncMock()
        self.aclose = AsyncMock()
        self.generate_reply = Mock()
        self.interrupt = AsyncMock()


class Room(rtc.EventEmitter):
    def __init__(self, grant):
        super().__init__()
        self.local_participant = SimpleNamespace(
            identity=grant.worker_identity,
            publish_data=AsyncMock(),
            register_rpc_method=Mock(),
        )
        self.remote_participants = {grant.participant_identity: object()}
        self.disconnect = AsyncMock()


class Context:
    def __init__(self, grant):
        self.room = Room(grant)
        self.job = SimpleNamespace(id="AJ_test")
        self.api = SimpleNamespace(room=SimpleNamespace(delete_room=AsyncMock()))
        self.connect = AsyncMock()
        self.wait_for_participant = AsyncMock()
        self.shutdown = Mock()
        self.callbacks = []

    def add_shutdown_callback(self, callback):
        self.callbacks.append(callback)


def setup(grant, monkeypatch):
    session, model = Session(), SimpleNamespace(aclose=AsyncMock())
    factory = Mock(return_value=(session, model, object()))
    monkeypatch.setattr("voca.runtime.build_session", factory)
    ctx = Context(grant)
    control = SimpleNamespace(
        settings=Settings(
            "https://control.example.test", "v1", "test-only-worker-secret-" + "w" * 32
        ),
        heartbeat=AsyncMock(
            return_value={"continue": True, "state": "starting", "expires_at": grant.expires_at}
        ),
        finish=AsyncMock(),
    )
    runtime = VoiceRuntime(ctx, control, grant)
    return runtime, session, model, ctx, control, factory


async def until_started(session):
    async with asyncio.timeout(2):
        while not session.generate_reply.called:
            await asyncio.sleep(0.001)


def test_real_runtime_orchestrates_only_audio_disables_recording_and_releases_all_resources(
    grant, monkeypatch
):
    async def exercise():
        runtime, session, model, ctx, control, factory = setup(grant, monkeypatch)
        task = asyncio.create_task(runtime.run())
        await until_started(session)
        assert session.start.await_args.kwargs["record"] is False
        assert session.start.await_args.kwargs["session_host"] is False
        assert session.start.await_args.kwargs["room_options"].text_input is False
        assert session.output.transcription.label == "voca-sanitized-captions"
        assert "AI assistant" in session.generate_reply.call_args.kwargs["instructions"]
        assert factory.call_count == 1
        runtime.stop()
        await asyncio.wait_for(task, 3)
        session.aclose.assert_awaited_once()
        model.aclose.assert_awaited_once()
        ctx.room.disconnect.assert_awaited_once()
        ctx.api.room.delete_room.assert_awaited_once()
        assert ctx.api.room.delete_room.await_args.args[0].room == grant.room
        control.finish.assert_awaited_once()
        assert runtime.http.closed
        assert runtime.cleaned
        assert not runtime.tasks
        assert ctx.shutdown.call_count == 1
        await runtime.shutdown()  # Exactly-once shutdown callback.
        control.finish.assert_awaited_once()

    asyncio.run(exercise())


def test_interrupt_rpc_rejects_wrong_caller_session_and_expired_lease_and_uses_sdk_cancellation(
    grant, monkeypatch
):
    async def exercise():
        runtime, session, *_ = setup(grant, monkeypatch)
        runtime.session = session
        bad = rtc.RpcInvocationData("test-id", "wrong-browser", "{}", 3)
        with pytest.raises(rtc.RpcError):
            await runtime.interrupt(bad)
        bad.caller_identity = grant.participant_identity
        with pytest.raises(rtc.RpcError):
            await runtime.interrupt(bad)
        session.interrupt.assert_not_awaited()
        bad.payload = json.dumps({"session_id": grant.session_id})
        assert json.loads(await runtime.interrupt(bad)) == {"interrupted": True}
        session.interrupt.assert_awaited_once_with(force=True)
        runtime.lease.authorized_until = 0
        with pytest.raises(rtc.RpcError):
            await runtime.interrupt(bad)

    asyncio.run(exercise())


def test_membership_revocation_control_reply_stops_media_before_opening_provider(
    grant, monkeypatch
):
    async def exercise():
        runtime, session, _, _, control, _ = setup(grant, monkeypatch)
        control.heartbeat.return_value = {
            "continue": False,
            "state": "revoked",
            "error_code": "access_revoked",
            "expires_at": grant.expires_at,
        }
        await runtime._heartbeat()
        assert runtime.done.is_set()
        assert runtime.error_code == "access_revoked"
        session.start.assert_not_awaited()
        await runtime.shutdown()

    asyncio.run(exercise())


def test_no_provider_context_is_reused_after_hard_expiry(grant, monkeypatch):
    async def exercise():
        runtime, session, *_ = setup(grant, monkeypatch)
        runtime.lease.deadline = 0
        await runtime._watchdog()
        assert runtime.error_code == "session_expired"
        session.start.assert_not_awaited()
        await runtime.shutdown()

    asyncio.run(exercise())


def test_final_transcripts_are_actual_sdk_items_interrupted_aware_and_redacted(grant, monkeypatch):
    async def exercise():
        runtime, session, *_ = setup(grant, monkeypatch)
        runtime.session = session
        runtime._attach_events()
        secret = grant.credentials.api_key.get_secret_value()
        item = llm.ChatMessage(
            id=secret, role="assistant", content=["Not live speech: " + secret], interrupted=True
        )
        session.emit("conversation_item_added", SimpleNamespace(item=item))
        packets = []
        while not runtime.events.queue.empty():
            packets.append(json.loads(runtime.events.queue.get_nowait()))
            runtime.events.queue.task_done()
        transcript = next(packet for packet in packets if packet["type"] == "transcript")
        assert secret not in json.dumps(packets)
        assert transcript["interrupted"] is True
        assert "[redacted]" in transcript["text"]
        assert transcript["id"] != secret
        await runtime.shutdown()

    asyncio.run(exercise())


@pytest.mark.parametrize(
    "status,code",
    [
        (401, "provider_auth"),
        (403, "provider_auth"),
        (429, "provider_quota"),
        (500, "provider_error"),
    ],
)
def test_error_mapping_uses_safe_status_not_upstream_messages(status, code):
    error = RuntimeError("Private raw error that must not be reflected.")
    error.status_code = status
    assert provider_reason(error) == code
    assert provider_reason(TimeoutError("private")) == "provider_timeout"
