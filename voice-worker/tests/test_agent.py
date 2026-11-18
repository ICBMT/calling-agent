import asyncio
import logging
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import agent


class HttpSession:
    async def __aenter__(self):
        return self

    async def __aexit__(self, *_args):
        return None


def context():
    return SimpleNamespace(
        job=SimpleNamespace(metadata="dispatch", room=SimpleNamespace(name="room"), id="AJ_test"),
        api=SimpleNamespace(room=SimpleNamespace(delete_room=AsyncMock())),
        shutdown=Mock(),
    )


def setup(monkeypatch, *, claim_result=None, claim_error=None, runtime_error=None):
    dispatch = SimpleNamespace(session_id="11111111-1111-4111-8111-111111111111")
    grant = SimpleNamespace(
        session_id=dispatch.session_id,
        room="voca-11111111-1111-4111-8111-111111111111",
    )
    control = SimpleNamespace(
        claim=AsyncMock(return_value=claim_result or grant),
        finish=AsyncMock(),
    )
    monkeypatch.setattr(agent.Settings, "from_env", Mock(return_value=object()))
    monkeypatch.setattr(agent.Dispatch, "parse", Mock(return_value=dispatch))
    monkeypatch.setattr(agent.aiohttp, "ClientSession", lambda **_kwargs: HttpSession())
    monkeypatch.setattr(agent, "ControlClient", Mock(return_value=control))
    if claim_error:
        control.claim.side_effect = claim_error
    if runtime_error:
        monkeypatch.setattr(agent, "VoiceRuntime", Mock(side_effect=runtime_error))
    else:
        runtime = SimpleNamespace(
            run=AsyncMock(),
            stop=Mock(),
            shutdown=AsyncMock(),
        )
        monkeypatch.setattr(agent, "VoiceRuntime", Mock(return_value=runtime))
    return dispatch, grant, control


def test_runtime_construction_failure_finishes_claimed_session_without_waiting_for_lease(
    monkeypatch, caplog
):
    secret = "private startup exception text"
    dispatch, grant, control = setup(monkeypatch, runtime_error=RuntimeError(secret))
    ctx = context()

    with caplog.at_level(logging.WARNING, logger="voca"):
        asyncio.run(agent.browser_session(ctx))

    control.finish.assert_awaited_once_with(grant, "AJ_test", {}, "worker_timeout")
    ctx.api.room.delete_room.assert_not_awaited()
    ctx.shutdown.assert_called_once_with(reason="browser voice job failed")
    records = [record for record in caplog.records if record.msg == "worker.job_failed"]
    assert len(records) == 1
    assert records[0].session_id == dispatch.session_id
    assert records[0].reason == "worker_timeout"
    assert records[0].exception_class == "RuntimeError"
    assert secret not in caplog.text


def test_runtime_construction_failure_deletes_room_if_control_finish_is_unavailable(
    monkeypatch, caplog
):
    _, grant, control = setup(monkeypatch, runtime_error=RuntimeError("private"))
    control.finish.side_effect = RuntimeError("private control-plane failure")
    ctx = context()

    with caplog.at_level(logging.WARNING, logger="voca"):
        asyncio.run(agent.browser_session(ctx))

    control.finish.assert_awaited_once()
    ctx.api.room.delete_room.assert_awaited_once()
    assert ctx.api.room.delete_room.await_args.args[0].room == grant.room
    assert any(record.msg == "worker.session_cleanup_failed" for record in caplog.records)
    assert "private" not in caplog.text


def test_claim_failure_is_not_misreported_as_dispatch_rejection(monkeypatch, caplog):
    _, _, control = setup(monkeypatch, claim_error=RuntimeError("private claim failure"))
    ctx = context()

    with caplog.at_level(logging.WARNING, logger="voca"):
        asyncio.run(agent.browser_session(ctx))

    control.finish.assert_not_awaited()
    ctx.api.room.delete_room.assert_not_awaited()
    assert any(record.msg == "worker.claim_failed" for record in caplog.records)
    assert not any(record.msg == "worker.rejected_job" for record in caplog.records)
    assert "private claim failure" not in caplog.text
