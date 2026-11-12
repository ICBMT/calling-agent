from __future__ import annotations

import json
import os
from collections.abc import Callable

import aiohttp
from livekit.agents import APIConnectOptions, Agent, AgentSession, llm, room_io
from livekit.agents.voice import SessionConnectOptions
from livekit.plugins.google.beta import realtime as google_realtime
from livekit.plugins.openai import realtime
from google.genai import types as google_types
from openai.types.realtime import AudioTranscription
from openai.types.realtime.realtime_audio_input_turn_detection import ServerVad

from .contracts import LANGUAGES, ContractError, SessionGrant


class ObservedRealtimeModel(realtime.RealtimeModel):
    """Observe the public realtime-session metric event, not deprecated AgentSession metrics."""

    def __init__(
        self, *, measured: Callable[[object], None], reconnected: Callable[[], None], **kwargs
    ):
        super().__init__(**kwargs)
        self._voca_measured = measured
        self._voca_reconnected = reconnected

    def session(self, *, turn_detection_disabled: bool = False) -> llm.RealtimeSession:
        session = super().session(turn_detection_disabled=turn_detection_disabled)
        session.on("metrics_collected", self._voca_measured)
        session.on("session_reconnected", lambda _event: self._voca_reconnected())
        return session


class ObservedGeminiRealtimeModel(google_realtime.RealtimeModel):
    """Observe the public realtime-session metric event for Gemini Live API."""

    def __init__(
        self, *, measured: Callable[[object], None], reconnected: Callable[[], None], **kwargs
    ):
        super().__init__(**kwargs)
        self._voca_measured = measured
        self._voca_reconnected = reconnected

    def session(self, *, turn_detection_disabled: bool = False) -> llm.RealtimeSession:
        session = super().session(turn_detection_disabled=turn_detection_disabled)
        session.on("metrics_collected", self._voca_measured)
        session.on("session_reconnected", lambda _event: self._voca_reconnected())
        return session


PERSONALITY_GUIDE = {
    "friendly": (
        "Warm, upbeat and personable. Use the caller\u2019s name once you know it, "
        "keep energy calm and positive, and let a little natural warmth come through."
    ),
    "professional": (
        "Polished, precise and efficient. Courteous but businesslike; skip small talk "
        "unless the caller starts it."
    ),
    "empathetic": (
        "Patient, gentle and reassuring. Acknowledge how the caller feels before solving "
        "anything; never rush their story."
    ),
    "concise": (
        "Crisp and direct. Short sentences, no filler, get to the point while staying polite."
    ),
}

VOICE_DISCIPLINE = (
    "Voice channel rules: speak in plain conversational language only - never read out markdown, "
    "bullet points, emoji, or URLs. Keep each turn to one or two short sentences unless the caller "
    "asks for detail. Ask exactly one question at a time and wait for the answer. "
    "When you capture a name, number, email or reference, read it back slowly to confirm it. "
    "If the line goes quiet or the audio is unclear, gently check in instead of guessing. "
    "If the caller interrupts, abandon your current sentence immediately and answer their newest point. "
    "Flow of the call: open warmly, understand the need in the caller\u2019s own words, help with it, "
    "then briefly recap, ask if anything else is needed, and close warmly."
)


def personality_guide(value: str) -> str:
    return PERSONALITY_GUIDE.get(value, PERSONALITY_GUIDE["friendly"])


def instructions(grant: SessionGrant) -> str:
    config = grant.configuration
    return (
        "You are an AI assistant in a browser voice test, not a human or a telephone call. "
        "Clearly disclose that you are an AI assistant in your opening. "
        + VOICE_DISCIPLINE
        + " Do not invent actions, account access or successful "
        "transfers: no external tools are connected in this test. "
        f"The requested spoken language is {LANGUAGES[config.language]}. Follow the user's "
        "language if they switch; Urdu-English and Hindi-English code switching is welcome. "
        "If you cannot understand, ask a short clarification instead of making up a transcript. "
        f"Requested personality: {config.personality} - {personality_guide(config.personality)}\n\n"
        "Saved draft instructions:\n"
        + config.instructions
        + "\n\nThis remains an AI browser test; do not claim to be human or to have completed "
        "unavailable real-world actions."
    )


def greeting_instructions(grant: SessionGrant) -> str:
    return (
        "Start with a short spoken greeting and explicitly introduce yourself as an AI assistant. "
        f"Use {LANGUAGES[grant.configuration.language]}. The draft opening below is optional "
        "wording, not an instruction to conceal your AI identity: "
        + json.dumps(grant.configuration.greeting, ensure_ascii=False)
    )


def build_session(
    grant: SessionGrant,
    http: aiohttp.ClientSession,
    measured: Callable[[object], None],
    reconnected: Callable[[], None],
) -> tuple[AgentSession, llm.RealtimeModel, Agent]:
    if grant.configuration.provider == "gemini":
        if os.environ.get("GEMINI_API_VERSION"):
            raise ContractError("Unsupported global provider override.")
        connection = APIConnectOptions(max_retry=0, timeout=12.0)
        model = ObservedGeminiRealtimeModel(
            model=grant.configuration.model,
            voice=grant.configuration.voice,
            api_key=grant.credentials.api_key.get_secret_value(),
            modalities=[google_types.Modality.AUDIO],
            instructions=instructions(grant),
            conn_options=connection,
            measured=measured,
            reconnected=reconnected,
        )
        session = AgentSession(
            llm=model,
            turn_handling={"turn_detection": "realtime_llm", "interruption": {"enabled": True}},
            user_away_timeout=None,
            conn_options=SessionConnectOptions(llm_conn_options=connection, max_unrecoverable_errors=1),
        )
        agent = Agent(instructions=instructions(grant), tools=[])
        return session, model, agent

    if os.environ.get("OPENAI_API_VERSION"):
        raise ContractError("Unsupported global provider override.")
    connection = APIConnectOptions(max_retry=0, timeout=12.0)
    model = ObservedRealtimeModel(
        model=grant.configuration.model,
        voice=grant.configuration.voice,
        api_key=grant.credentials.api_key.get_secret_value(),
        base_url="https://api.openai.com/v1",
        http_session=http,
        modalities=["audio"],
        tracing=None,
        max_session_duration=None,
        input_audio_transcription=AudioTranscription(
            model=grant.configuration.transcription_model,
            languages=[grant.configuration.language]
            if grant.configuration.language == "en"
            else [grant.configuration.language, "en"],
        ),
        input_audio_noise_reduction="near_field",
        # Silence-based provider VAD is language-independent. Do not silently use a paid
        # LiveKit Inference turn detector or claim Urdu/Hindi semantic-turn quality is proven.
        turn_detection=ServerVad(
            type="server_vad",
            threshold=0.5,
            prefix_padding_ms=300,
            silence_duration_ms=500,
            create_response=True,
            interrupt_response=True,
        ),
        conn_options=connection,
        measured=measured,
        reconnected=reconnected,
    )
    session = AgentSession(
        llm=model,
        turn_handling={"turn_detection": "realtime_llm", "interruption": {"enabled": True}},
        user_away_timeout=None,
        conn_options=SessionConnectOptions(llm_conn_options=connection, max_unrecoverable_errors=1),
    )
    # New agent and ChatContext for every job. No global provider object or cross-session memory.
    agent = Agent(instructions=instructions(grant), tools=[])
    return session, model, agent


def room_options(grant: SessionGrant) -> room_io.RoomOptions:
    sample_rate = 16000 if grant.configuration.provider == "gemini" else 24000
    return room_io.RoomOptions(
        participant_identity=grant.participant_identity,
        audio_input=room_io.AudioInputOptions(
            sample_rate=sample_rate, num_channels=1, frame_size_ms=20, pre_connect_audio=False
        ),
        audio_output=room_io.AudioOutputOptions(sample_rate=24000, num_channels=1),
        video_input=False,
        text_input=False,
        text_output=False,  # A per-job sanitized TextOutput owns captions; no raw SDK text channel.
        close_on_disconnect=False,
        delete_room_on_close=False,
    )
