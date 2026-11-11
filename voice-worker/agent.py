from __future__ import annotations

# Install privacy before SDK imports and again in each spawned job process. Never enable raw SDK logs.
from voca.privacy import install_safe_logging, safe_log

install_safe_logging()

import os
import re
import sys

import aiohttp
from livekit import rtc
from livekit.agents import AgentServer, JobContext, JobRequest, WorkerPermissions, cli

from voca.contracts import ContractError, Dispatch
from voca.control import ControlClient
from voca.runtime import VoiceRuntime
from voca.settings import Settings


async def accept_job(request: JobRequest) -> None:
    try:
        dispatch = Dispatch.parse(request.job.metadata, request.job.room.name)
        if not re.fullmatch(r"[A-Za-z0-9_-]{1,100}", request.job.id):
            raise ContractError("Invalid job id.")
    except ContractError:
        safe_log("worker.rejected_job")
        await request.reject()
        return
    await request.accept(identity=f"voca-agent-{dispatch.session_id}")


def setup_process(_process) -> None:
    install_safe_logging()


server = AgentServer(
    host="0.0.0.0",
    port=8081,
    num_idle_processes=1,
    job_memory_warn_mb=512,
    job_memory_limit_mb=1024,
    drain_timeout=900,
    shutdown_process_timeout=30,
    max_retry=5,
    setup_fnc=setup_process,
    permissions=WorkerPermissions(
        can_publish=True,
        can_subscribe=True,
        can_publish_data=True,
        can_update_metadata=True,
        can_publish_sources=[rtc.TrackSource.SOURCE_MICROPHONE],
    ),
    log_level="WARN",
)


@server.rtc_session(
    agent_name=os.environ.get("VOICE_WORKER_NAME", "voca-browser-v1"), on_request=accept_job
)
async def browser_session(ctx: JobContext) -> None:
    try:
        settings = Settings.from_env()
        dispatch = Dispatch.parse(ctx.job.metadata, ctx.job.room.name)
        async with aiohttp.ClientSession(
            cookie_jar=aiohttp.DummyCookieJar(),
            trust_env=False,
        ) as http:
            control = ControlClient(settings, http)
            grant = await control.claim(dispatch, ctx.job.id)
            runtime = VoiceRuntime(ctx, control, grant)
            await runtime.run()
    except Exception:
        # A failed one-use claim is not retried. PHP expiry/sweep retains the cleanup obligation.
        safe_log("worker.rejected_job")
        ctx.shutdown(reason="browser voice job rejected")


if __name__ == "__main__":
    if not any(arg in {"--help", "-h"} for arg in sys.argv[1:]):
        try:
            Settings.from_env()
            if (
                (
                    not os.environ.get("LIVEKIT_URL", "").startswith("wss://")
                    and not (
                        os.environ.get("VOICE_ALLOW_INSECURE_LOCAL") == "true"
                        and os.environ.get("LIVEKIT_URL", "").startswith(
                            ("ws://localhost:", "ws://127.0.0.1:")
                        )
                    )
                )
                or not os.environ.get("LIVEKIT_API_KEY")
                or len(os.environ.get("LIVEKIT_API_SECRET", "")) < 32
            ):
                raise ContractError("Invalid LiveKit environment.")
        except ContractError:
            print(
                "Worker setup is incomplete or unsafe. See voice-worker/README.md; do not paste keys in chat.",
                file=sys.stderr,
            )
            raise SystemExit(1) from None
        safe_log("worker.starting")
    cli.run_app(server)
