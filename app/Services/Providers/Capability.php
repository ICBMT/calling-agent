<?php

namespace App\Services\Providers;

enum Capability: string
{
    case Realtime = 'realtime';
    case SpeechToText = 'stt';
    case LanguageModel = 'llm';
    case TextToSpeech = 'tts';

    public function label(): string
    {
        return match ($this) {
            self::Realtime => 'Native realtime voice',
            self::SpeechToText => 'Speech to text',
            self::LanguageModel => 'Language model',
            self::TextToSpeech => 'Text to speech',
        };
    }

    public function needsVoice(): bool
    {
        return in_array($this, [self::Realtime, self::TextToSpeech], true);
    }
}
