from __future__ import annotations

import asyncio
import contextlib
import hashlib
import json
import os
import time

import aiohttp
from livekit import api, rtc
from livekit.agents import AutoSubscribe, JobContext, llm

from .contracts import ERROR_CODES, SessionGrant, load_object
from .captions import Captions
from .control import ControlClient, ControlError
from .events import RoomEvents
from .lease import AuthorityLease
from .metrics import Measurements
from .native import build_session, greeting_instructions, room_options
from .privacy import safe_log, safe_text


def provider_reason(error: object) -> str:
    current = error
    for _ in range(5):
        status = getattr(current, "status_code", getattr(current, "status", None))
        code = getattr(current, "code", None)
        if status in {401, 403, 404} or code in {
            "invalid_api_key",
            "model_not_found",
            "permission_denied",
        }:
            return "provider_auth"
        if status == 429 or code in {"insufficient_quota", "rate_limit_exceeded", "quota_exceeded"}:
            return "provider_quota"
        if isinstance(current, TimeoutError):
            return "provider_timeout"
        current = getattr(current, "error", None) or getattr(current, "__cause__", None)
        if current is None:
            break
    return "provider_error"


class VoiceRuntime:
    def __init__(self, ctx: JobContext, control: ControlClient, grant: SessionGrant):
        self.ctx, self.control, self.grant = ctx, control, grant
        self.job_id = ctx.job.id
        self.state = "starting"
        self.error_code: str | None = None
        self.done = asyncio.Event()
        self.closing = False
        self.cleanup_lock = asyncio.Lock()
        self.control_lock = asyncio.Lock()
        self.cleaned = False
        self.lease = AuthorityLease(grant.expires_at, grant.lease_seconds)
        self.events = RoomEvents(ctx.room, grant, lambda: self.stop("browser_disconnected"))
        self.measured = Measurements(lambda values: self.events.emit("metrics", values=values))
        self.session = self.model = None
        self.http: aiohttp.ClientSession | None = None
        self.tasks: set[asyncio.Task] = set()
        self.thinking_since: float | None = None
        self.missing_since: float | None = None
        self.joined = False
        self.last_interrupt = 0.0
        self.secrets = (
            grant.credentials.api_key.get_secret_value(),
            control.settings.secret,
            os.environ.get("LIVEKIT_API_SECRET", ""),
        )

    def ui_id(self, value: str) -> str:
        return hashlib.sha256((self.grant.session_id + value).encode()).hexdigest()[:32]

    def stop(self, reason: str | None = None) -> None:
        if self.done.is_set():
            return
        self.error_code = reason if isinstance(reason, str) and reason in ERROR_CODES else None
        if self.error_code:
            self.events.emit("error", code=self.error_code)
        self.done.set()

    async def interrupt(self, call: rtc.RpcInvocationData) -> str:
        if (
            call.caller_identity != self.grant.participant_identity
            or len(call.payload) > 150
            or self.closing
            or not self.session
            or self.lease.reason() is not None
        ):
            raise rtc.RpcError(1400, "Interrupt request rejected.")
        try:
            payload = load_object(call.payload, 150)
            if payload != {"session_id": self.grant.session_id}:
                raise ValueError
        except Exception:
            raise rtc.RpcError(1400, "Interrupt request rejected.") from None
        if time.monotonic() - self.last_interrupt < 0.4:
            raise rtc.RpcError(1500, "Please wait before interrupting again.")
        self.last_interrupt = time.monotonic()
        # SDK stops playout, response.cancel and conversation.item.truncate together.
        await asyncio.wait_for(self.session.interrupt(force=True), timeout=3)
        self.events.emit("interrupted")
        return json.dumps({"interrupted": True})

    def _attach_events(self) -> None:
        @self.session.on("user_input_transcribed")
        def user_transcript(event):
            if not self.closing and event.transcript:
                self.events.emit(
                    "user_partial",
                    id=self.ui_id(event.item_id or "current-user"),
                    text=safe_text(event.transcript, self.secrets),
                    final=event.is_final,
                )

        @self.session.on("conversation_item_added")
        def conversation(event):
            item = event.item
            if (
                self.closing
                or not isinstance(item, llm.ChatMessage)
                or item.role not in {"user", "assistant"}
            ):
                return
            self.measured.conversation(item)
            if item.text_content:
                self.events.emit(
                    "transcript",
                    id=self.ui_id(item.id),
                    role=item.role,
                    text=safe_text(item.text_content, self.secrets),
                    final=True,
                    interrupted=item.interrupted,
                    created_at=item.created_at,
                )
            else:
                self.events.emit("transcript_unavailable", role=item.role)

        @self.session.on("session_usage_updated")
        def usage(event):
            if not self.closing:
                self.measured.usage(event.usage, self.grant.configuration.model)

        @self.session.on("agent_state_changed")
        def agent_state(event):
            if not self.closing:
                self.thinking_since = time.monotonic() if event.new_state == "thinking" else None
                self.events.emit("agent_state", state=event.new_state)

        @self.session.on("user_state_changed")
        def user_state(event):
            if not self.closing:
                self.events.emit("user_state", state=event.new_state)

        @self.session.on("error")
        def error(event):
            # Never serialize the SDK error/source: they can contain provider/request payloads.
            if not getattr(event.error, "recoverable", False):
                self.stop(provider_reason(event.error))

        @self.session.on("close")
        def closed(event):
            if not self.closing:
                self.stop(provider_reason(event.error) if event.error else None)

        @self.ctx.room.on("participant_disconnected")
        def disconnected(participant):
            if participant.identity == self.grant.participant_identity and not self.closing:
                self.missing_since = time.monotonic()
                # No replay of old output when the browser rejoins this room.
                self._spawn(self._cancel_on_disconnect())

    def _spawn(self, coroutine) -> asyncio.Task:
        task = asyncio.create_task(coroutine)
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)
        return task

    async def _cancel_on_disconnect(self) -> None:
        try:
            if self.session:
                await asyncio.wait_for(self.session.interrupt(force=True), timeout=3)
        except Exception:
            self.stop("browser_disconnected")

    async def _heartbeat(self) -> None:
        while not self.done.is_set():
            try:
                async with self.control_lock:
                    result = await self.control.heartbeat(
                        self.grant, self.job_id, self.state, self.measured.snapshot()
                    )
                if not result["continue"]:
                    self.stop(result.get("error_code") or "session_ended")
                    return
                if not self.lease.renew():
                    self.stop(self.lease.reason())
                    return
                await asyncio.sleep(self.grant.heartbeat_seconds)
            except ControlError as error:
                safe_log("session.control_unavailable", self.grant.session_id)
                if error.status in {400, 401, 403, 404, 409, 422}:
                    self.stop("worker_timeout")
                    return
                # Retry only an idempotent authority pulse within the existing monotonic lease.
                await asyncio.sleep(1)
            except asyncio.CancelledError:
                raise
            except Exception:
                self.stop("worker_timeout")
                return

    async def _watchdog(self) -> None:
        while not self.done.is_set():
            reason = self.lease.reason()
            if reason:
                self.stop(reason)
                return
            if self.thinking_since and time.monotonic() - self.thinking_since >= 30:
                self.stop("provider_timeout")
                return
            if self.joined:
                present = self.grant.participant_identity in self.ctx.room.remote_participants
                if present:
                    self.missing_since = None
                elif self.missing_since is None:
                    self.missing_since = time.monotonic()
                elif time.monotonic() - self.missing_since > 20:
                    self.stop("browser_disconnected")
                    return
            await asyncio.sleep(0.25)

    async def _media(self) -> None:
        try:
            await asyncio.wait_for(self.ctx.connect(auto_subscribe=AutoSubscribe.AUDIO_ONLY), 15)
            self.events.start()
            if self.ctx.room.local_participant.identity != self.grant.worker_identity:
                self.stop("access_revoked")
                return
            try:
                await asyncio.wait_for(
                    self.ctx.wait_for_participant(identity=self.grant.participant_identity), 30
                )
            except TimeoutError:
                self.stop("participant_timeout")
                return
            self.joined = True
            headers = {
                key: value
                for key, value in {
                    "OpenAI-Project": self.grant.credentials.project_id,
                    "OpenAI-Organization": self.grant.credentials.organization,
                }.items()
                if value
            }
            self.http = aiohttp.ClientSession(
                headers=headers,
                cookie_jar=aiohttp.DummyCookieJar(),
                trust_env=False,
                timeout=aiohttp.ClientTimeout(total=20, connect=5),
            )
            self.session, self.model, agent = build_session(
                self.grant,
                self.http,
                self.measured.realtime,
                # No hidden provider reconnect/context replay in this first factory.
                lambda: self.stop("provider_error"),
            )
            self.session.output.transcription = Captions(self.events, self.secrets)
            self._attach_events()
            self.ctx.room.local_participant.register_rpc_method("voca.interrupt", self.interrupt)
            await asyncio.wait_for(
                self.session.start(
                    agent=agent,
                    room=self.ctx.room,
                    room_options=room_options(self.grant),
                    record=False,
                    session_host=False,
                ),
                20,
            )
            if self.done.is_set():
                return
            self.state = "active"
            self.events.emit("ready")
            # Live native generation, not TTS of a canned recording. Each new room has fresh context.
            self.session.generate_reply(instructions=greeting_instructions(self.grant))
        except asyncio.CancelledError:
            raise
        except Exception as error:
            safe_log("session.media_error", self.grant.session_id)
            self.stop(provider_reason(error))

    async def run(self) -> None:
        loop = asyncio.get_running_loop()
        loop.set_exception_handler(
            lambda _loop, _context: safe_log("session.media_error", self.grant.session_id)
        )
        self.ctx.add_shutdown_callback(self.shutdown)
        self._spawn(self._heartbeat())
        self._spawn(self._watchdog())
        self._spawn(self._media())
        safe_log("session.claimed", self.grant.session_id)
        try:
            await self.done.wait()
        finally:
            await self.shutdown()
            self.ctx.shutdown(reason="browser voice session ended")

    async def shutdown(self) -> None:
        async with self.cleanup_lock:
            if self.cleaned:
                return
            self.closing = True
            self.done.set()
            for task in list(self.tasks):
                task.cancel()
            await asyncio.gather(*list(self.tasks), return_exceptions=True)
            for resource in (self.session, self.model):
                if resource:
                    with contextlib.suppress(Exception):
                        await asyncio.wait_for(resource.aclose(), 4)
            with contextlib.suppress(Exception):
                await asyncio.wait_for(self.events.close(), 2)
            with contextlib.suppress(Exception):
                async with self.control_lock:
                    await self.control.finish(
                        self.grant, self.job_id, self.measured.snapshot(), self.error_code
                    )
            # Independently clean the room even if Laravel is down/the actor or session was deleted.
            try:
                await asyncio.wait_for(
                    self.ctx.api.room.delete_room(api.DeleteRoomRequest(room=self.grant.room)), 4
                )
            except Exception:
                safe_log("session.cleanup_pending", self.grant.session_id)
            with contextlib.suppress(Exception):
                await asyncio.wait_for(self.ctx.room.disconnect(), 3)
            if self.http:
                await self.http.close()
            self.cleaned = True
            safe_log("session.stopped", self.grant.session_id, self.error_code)
