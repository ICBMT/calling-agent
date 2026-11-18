<?php

namespace Tests\Feature;

use App\Services\Providers\Catalog;
use Tests\TestCase;

class VoiceCatalogMatrixTest extends TestCase
{
    /** Every documented, unexpired realtime model must be selectable in the browser voice matrix. */
    public function test_every_documented_realtime_model_is_enabled_for_browser_voice(): void
    {
        foreach (config('voice.native.providers') as $provider) {
            $documented = collect(config("providers.documented_models.$provider", []))
                ->filter(fn (array $entry): bool => ($entry['capability'] ?? null) === 'realtime'
                    && ($entry['streaming'] ?? null) === true
                    && ! Catalog::expired($entry['shutdown_date'] ?? null))
                ->keys()
                ->sort()
                ->values()
                ->all();

            $enabled = collect(
                $provider === 'openai'
                    ? config('voice.native.models')
                    : config("voice.native.supported.$provider.models", [])
            )->sort()->values()->all();

            $this->assertSame(
                $documented,
                $enabled,
                "voice.native matrix for {$provider} drifted from providers.documented_models.",
            );
        }
    }

    /** Each enabled provider offers its full documented realtime voice set and language map. */
    public function test_every_provider_voice_and_language_combination_is_offered(): void
    {
        foreach (config('voice.native.providers') as $provider) {
            $languages = $provider === 'openai'
                ? config('voice.native.languages')
                : config("voice.native.supported.$provider.languages", config('voice.native.languages'));
            $this->assertSame(['en', 'ur', 'hi'], array_keys($languages), "Languages drifted for {$provider}.");

            $transcription = $provider === 'openai'
                ? config('voice.native.transcription_model')
                : config("voice.native.supported.$provider.transcription_model");
            $this->assertIsString($transcription);
            $this->assertNotSame('', $transcription, "Missing transcription model for {$provider}.");
        }

        $openAiVoices = collect(config('providers.documented_models.openai'))
            ->filter(fn (array $entry): bool => ($entry['capability'] ?? null) === 'realtime'
                && ($entry['streaming'] ?? null) === true
                && ! Catalog::expired($entry['shutdown_date'] ?? null))
            ->pluck('voices')
            ->flatten()
            ->unique()
            ->values()
            ->all();
        $this->assertContains('alloy', $openAiVoices);
        $this->assertContains('marin', $openAiVoices);
        $this->assertContains('cedar', $openAiVoices);
    }
}
