from __future__ import annotations

import hashlib
import hmac
import json
import secrets
import time
from collections.abc import Callable

import aiohttp

from .contracts import ERROR_CODES, ContractError, Dispatch, SessionGrant, load_object
from .settings import Settings


class ControlError(Exception):
    def __init__(self, code: str = "control_unavailable", status: int | None = None):
        super().__init__("The control-plane request could not be completed.")
        self.code = code
        self.status = status


def signed_headers(
    settings: Settings, path: str, body: bytes, *, timestamp: int, nonce: str
) -> dict[str, str]:
    canonical = "\n".join(
        [
            "v1",
            settings.key_id,
            "POST",
            path,
            str(timestamp),
            nonce,
            hashlib.sha256(body).hexdigest(),
        ]
    )
    signature = hmac.new(settings.secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    return {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-Voca-Key-Id": settings.key_id,
        "X-Voca-Timestamp": str(timestamp),
        "X-Voca-Nonce": nonce,
        "X-Voca-Signature": signature,
    }


class ControlClient:
    """No redirects, proxies, cookies, raw error bodies, automatic claim retries, or DB access."""

    def __init__(
        self,
        settings: Settings,
        http: aiohttp.ClientSession,
        clock: Callable[[], float] = time.time,
    ):
        self.settings = settings
        self.http = http
        self.clock = clock
        self.sequence = 0

    async def post(self, session_id: str, operation: str, data: dict) -> dict:
        if operation not in {"claim", "heartbeat", "finish"}:
            raise ContractError("Invalid control-plane operation.")
        # The session ID has already been canonical-UUID validated by Dispatch.parse.
        from uuid import UUID

        try:
            if str(UUID(session_id)) != session_id:
                raise ValueError
        except (ValueError, TypeError):
            raise ContractError("Invalid control-plane operation.") from None
        path = f"/internal/voice/sessions/{session_id}/{operation}"
        body = json.dumps(data, separators=(",", ":"), ensure_ascii=False, allow_nan=False).encode()
        if len(body) > 8192:
            raise ContractError("Invalid control-plane operation.")
        headers = signed_headers(
            self.settings, path, body, timestamp=int(self.clock()), nonce=secrets.token_hex(16)
        )
        try:
            async with self.http.post(
                self.settings.origin + path,
                data=body,
                headers=headers,
                allow_redirects=False,
                timeout=aiohttp.ClientTimeout(total=6, connect=3),
            ) as response:
                if response.status != 200:
                    # Never stringify/retain a remote JSON error or HTTP exception chain.
                    raise ControlError(status=response.status)
                raw = bytearray()
                async for chunk in response.content.iter_chunked(16384):
                    raw.extend(chunk)
                    if len(raw) > 65536:
                        raise ControlError()
                return load_object(bytes(raw))
        except ControlError:
            raise
        except (aiohttp.ClientError, TimeoutError, ContractError, ValueError, TypeError):
            raise ControlError() from None

    async def claim(self, dispatch: Dispatch, job_id: str) -> SessionGrant:
        result = await self.post(
            dispatch.session_id,
            "claim",
            {"room": dispatch.room, "ticket": dispatch.ticket.get_secret_value(), "job_id": job_id},
        )
        return SessionGrant.parse(result, dispatch, now=self.clock())

    def lease_data(self, grant: SessionGrant, job_id: str, metrics: dict) -> dict:
        self.sequence += 1
        return {
            "room": grant.room,
            "job_id": job_id,
            "lease": grant.lease.get_secret_value(),
            "sequence": self.sequence,
            "metrics": metrics,
        }

    async def heartbeat(self, grant: SessionGrant, job_id: str, state: str, metrics: dict) -> dict:
        data = self.lease_data(grant, job_id, metrics)
        data["state"] = state
        result = await self.post(grant.session_id, "heartbeat", data)
        if (
            type(result.get("continue")) is not bool
            or result.get("state")
            not in {"starting", "active", "ended", "failed", "expired", "revoked"}
            or type(result.get("expires_at")) is not int
            or result["expires_at"] != grant.expires_at
            or (result["continue"] and result["state"] not in {"starting", "active"})
            or (
                result.get("error_code") is not None
                and (
                    not isinstance(result["error_code"], str)
                    or result["error_code"] not in ERROR_CODES
                )
            )
        ):
            raise ControlError()
        return result

    async def finish(
        self, grant: SessionGrant, job_id: str, metrics: dict, error_code: str | None
    ) -> None:
        data = self.lease_data(grant, job_id, metrics)
        # Revocation already invalidates the lease server-side; never send an arbitrary reason.
        if error_code in {"access_revoked", "session_ended"}:
            return
        data["error_code"] = error_code
        await self.post(grant.session_id, "finish", data)
