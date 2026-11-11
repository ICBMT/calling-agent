from __future__ import annotations

import logging
import re
import sys
from typing import Any

SAFE_EVENTS = frozenset(
    {
        "worker.starting",
        "worker.rejected_job",
        "session.claimed",
        "session.stopped",
        "session.cleanup_pending",
        "session.control_unavailable",
        "session.media_error",
    }
)
_STANDARD = frozenset(logging.LogRecord("", 0, "", 0, "", (), None).__dict__) | {
    "message",
    "asctime",
}
_original_make_record = logging.Logger.makeRecord
_installed = False


def safe_record(record: logging.LogRecord) -> logging.LogRecord:
    """Sanitize AFTER Logger.makeRecord applies extras, including SDK body-bearing extras."""
    own_event = record.name == "voca" and isinstance(record.msg, str) and record.msg in SAFE_EVENTS
    exception_class = None
    if record.exc_info and record.exc_info[0]:
        exception_class = record.exc_info[0].__name__
    session_id = getattr(record, "session_id", None)
    reason = getattr(record, "reason", None)
    for key in list(record.__dict__):
        if key not in _STANDARD:
            del record.__dict__[key]
    record.name = (
        record.name if re.fullmatch(r"[A-Za-z0-9_.-]{1,100}", record.name) else "dependency"
    )
    record.msg = (
        record.msg if own_event else "Dependency event; content suppressed by voice privacy policy."
    )
    record.args = ()
    record.exc_info = None
    record.exc_text = None
    record.stack_info = None
    record.__dict__.pop("message", None)
    if exception_class and re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]{0,99}", exception_class):
        record.exception_class = exception_class
    if own_event:
        if isinstance(session_id, str) and re.fullmatch(r"[a-f0-9-]{36}", session_id):
            record.session_id = session_id
        from .contracts import ERROR_CODES

        if isinstance(reason, str) and reason in ERROR_CODES:
            record.reason = reason
    return record


def install_safe_logging() -> None:
    global _installed
    if _installed:
        return
    _installed = True

    def make_record(self: logging.Logger, *args: Any, **kwargs: Any) -> logging.LogRecord:
        return safe_record(_original_make_record(self, *args, **kwargs))

    # Scope: this independently deployed Python worker process only. Sanitizes before IPC/formatting,
    # unlike a root-logger filter, which does not run for propagated dependency records.
    logging.Logger.makeRecord = make_record

    def unhandled(kind: type[BaseException], _value: BaseException, _traceback: object) -> None:
        logging.getLogger("voca").error("session.media_error", exc_info=(kind, None, None))

    sys.excepthook = unhandled


def safe_log(code: str, session_id: str | None = None, reason: str | None = None) -> None:
    logging.getLogger("voca").warning(code, extra={"session_id": session_id, "reason": reason})


def safe_text(text: str, secrets: tuple[str, ...] = ()) -> str:
    for secret in secrets:
        if secret:
            text = text.replace(secret, "[redacted]")
    # Preserve Unicode/Urdu/Devanagari and newlines; never use text as HTML or a log message.
    return re.sub(r"[\x00-\x08\x0b-\x1f\x7f]", "", text)[:3000]
