import asyncio
import copy
import time

import aiohttp

from livekit.agents import llm
from livekit.agents.metrics import AgentSessionUsage, LLMModelUsage, RealtimeModelMetrics

from voca.contracts import SessionGrant
from voca.lease import AuthorityLease
from voca.metrics import Measurements
from voca.native import build_session, greeting_instructions, instructions, room_options


def test_real_pinned_sdk_constructs_provider_plugin_without_inference_or_global_key(
    grant, monkeypatch
):
    monkeypatch.setenv("OPENAI_API_KEY", "wrong-global-key-must-not-be-used")
    monkeypatch.setenv("OPENAI_BASE_URL", "https://wrong.example.test")

    async def exercise():
        async with aiohttp.ClientSession() as http:
            session, model, agent = build_session(grant, http, lambda _event: None, lambda: None)
            assert model._opts.api_key == grant.credentials.api_key.get_secret_value()
            assert model._opts.base_url == "https://api.openai.com/v1"
            assert model._opts.modalities == ["audio"]
            assert model._opts.tracing is None
            assert model._opts.max_session_duration is None
            assert model._opts.conn_options.max_retry == 0
            assert model._opts.input_audio_transcription.model == "gpt-transcribe"
            assert model._opts.input_audio_transcription.languages == ["en"]
            assert model._opts.turn_detection.type == "server_vad"
            assert model._opts.turn_detection.interrupt_response is True
            assert model.capabilities.message_truncation is True
            assert model.capabilities.audio_output is True
            assert session._opts.turn_handling["turn_detection"] == "realtime_llm"
            assert "AI assistant" in agent.instructions
            assert grant.credentials.api_key.get_secret_value() not in agent.instructions
            await model.aclose()

    asyncio.run(exercise())


def test_every_session_constructs_new_model_agent_and_context(grant, grant_data, dispatch):
    other = copy.deepcopy(grant_data)
    other["configuration"]["instructions"] = (
        "Private second workspace instructions, never shared with the first."
    )
    other["credentials"]["api_key"] = "test-only-different-project-key"
    second = SessionGrant.parse(other, dispatch)

    async def exercise():
        async with aiohttp.ClientSession() as http:
            session_a, model_a, agent_a = build_session(grant, http, lambda _: None, lambda: None)
            session_b, model_b, agent_b = build_session(second, http, lambda _: None, lambda: None)
            assert session_a is not session_b and model_a is not model_b and agent_a is not agent_b
            assert agent_a._chat_ctx is not agent_b._chat_ctx
            assert "second workspace" not in agent_a.instructions
            assert model_a._opts.api_key != model_b._opts.api_key
            await model_a.aclose()
            await model_b.aclose()

    asyncio.run(exercise())


def test_urdu_hindi_are_explicit_language_hints_not_a_claim_of_tested_quality(grant_data, dispatch):
    for code, language in (("ur", "Urdu"), ("hi", "Hindi")):
        grant_data["configuration"]["language"] = code
        grant = SessionGrant.parse(grant_data, dispatch)
        assert language in instructions(grant)
        assert language in greeting_instructions(grant)
        assert grant.configuration.greeting in greeting_instructions(grant)


def test_media_options_are_audio_only_exact_participant_and_24khz_mono(grant):
    options = room_options(grant)
    assert options.participant_identity == grant.participant_identity
    assert options.text_input is False and options.video_input is False
    assert options.text_output is False  # No raw transcription path around sanitized UI events.
    assert options.audio_input.sample_rate == 24000
    assert options.audio_input.num_channels == 1
    assert options.audio_input.frame_size_ms == 20
    assert options.audio_input.pre_connect_audio is False
    assert options.audio_output.sample_rate == 24000
    assert options.close_on_disconnect is False


def test_unknown_metrics_remain_absent_and_native_latency_labels_do_not_pretend_modular_measurements():
    values = Measurements()
    assert values.snapshot() == {}
    metric = RealtimeModelMetrics(
        request_id="test-response",
        timestamp=time.time(),
        ttft=-1,
        input_token_details=RealtimeModelMetrics.InputTokenDetails(),
        output_token_details=RealtimeModelMetrics.OutputTokenDetails(),
    )
    values.realtime(metric)
    assert "provider_first_audio_ms" not in values.snapshot()
    metric.ttft = 0.123
    values.realtime(metric)
    values.conversation(
        llm.ChatMessage(
            role="assistant",
            content=["A fixture transcript, not generated speech."],
            interrupted=True,
            metrics={"e2e_latency": 0.456},
        )
    )
    assert values.snapshot() == {
        "provider_first_audio_ms": 123,
        "worker_turn_ms": 456,
        "turns": 1,
        "interruptions": 1,
    }
    usage = AgentSessionUsage(
        model_usage=[
            LLMModelUsage(
                provider="openai",
                model="gpt-realtime-2.1",
                input_tokens=20,
                output_tokens=4,
                input_audio_tokens=12,
                output_audio_tokens=3,
            )
        ]
    )
    values.usage(usage, "gpt-realtime-2.1")
    values.usage(usage, "gpt-realtime-2.1")
    assert values.snapshot()["input_tokens"] == 20
    assert values.snapshot()["output_tokens"] == 4


def test_missing_invalid_negative_nan_and_infinite_timings_are_not_zero_slas():
    values = Measurements()
    for invalid in (None, "1", -1, float("nan"), float("inf"), 121):
        values._milliseconds("worker_turn_ms", invalid)
    assert values.snapshot() == {}


def test_monotonic_lease_never_resurrects_or_extends_hard_deadline():
    now = [0.0]
    lease = AuthorityLease(600, 25, clock=lambda: now[0], wall=lambda: 0)
    assert lease.reason() is None
    now[0] = 20
    assert lease.renew() is True
    now[0] = 46
    assert lease.reason() == "worker_timeout"
    assert lease.renew() is False
    now[0] = 601
    assert lease.reason() == "session_expired"
    assert lease.renew() is False


def test_clock_skew_cannot_create_unbounded_provider_lifetime():
    now = [0.0]
    lease = AuthorityLease(9999999999, 1000, clock=lambda: now[0], wall=lambda: 0)
    assert lease.deadline == 900
    assert lease.authorized_until == 30
