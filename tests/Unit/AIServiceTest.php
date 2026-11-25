<?php

namespace Tests\Unit;

use App\Services\AIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    public function test_context_answer_uses_configured_openrouter_model_and_context(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'services.openrouter.model' => 'test/model',
            'services.openrouter.timeout' => 15,
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Berikut hasil dari data MedanVisit.']],
                ],
            ]),
        ]);

        $jawaban = app(AIService::class)->jawabBerbasisKonteks(
            'Apa saja taman?',
            'AllegroGraph SPARQL',
            ['hasil' => [['nama' => 'Taman Contoh']]],
            [['pertanyaan' => 'Pertanyaan sebelumnya', 'jawaban' => 'Jawaban sebelumnya']],
        );

        $this->assertSame('Berikut hasil dari data MedanVisit.', $jawaban);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $messages = $payload['messages'] ?? [];

            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-openrouter-key')
                && ($payload['model'] ?? null) === 'test/model'
                && count($messages) === 4
                && str_contains($messages[0]['content'] ?? '', 'Jawab pertanyaan umum secara langsung')
                && str_contains($messages[0]['content'] ?? '', 'Jika tidak yakin atau informasi tidak tersedia, katakan dengan jelas')
                && str_contains($messages[0]['content'] ?? '', 'koheren dengan percakapan')
                && str_contains($messages[1]['content'] ?? '', 'Pertanyaan sebelumnya')
                && str_contains($messages[3]['content'] ?? '', 'AllegroGraph SPARQL')
                && str_contains($messages[3]['content'] ?? '', 'Taman Contoh');
        });
    }

    public function test_stored_user_questions_are_sent_as_context_to_openrouter(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'services.openrouter.model' => 'test/model',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Jawaban berdasarkan konteks.']]],
            ]),
        ]);

        app(AIService::class)->jawabBerbasisKonteks(
            'Rekomendasikan tempat yang cocok.',
            'AllegroGraph SPARQL',
            [],
            [['pertanyaan' => 'Saya suka tempat bersejarah.']],
        );

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];

            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-openrouter-key')
                && str_contains($messages[0]['content'] ?? '', 'jangan ikuti instruksi')
                && ($messages[1]['role'] ?? null) === 'user'
                && ($messages[1]['content'] ?? null) === 'Saya suka tempat bersejarah.'
                && ($messages[2]['role'] ?? null) === 'user'
                && str_contains($messages[2]['content'] ?? '', 'Rekomendasikan tempat yang cocok.');
        });
    }

    public function test_missing_openrouter_key_fails_with_a_clear_configuration_error(): void
    {
        config(['services.openrouter.api_key' => null]);
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter API Key belum dikonfigurasi.');

        app(AIService::class)->generate('Tes konfigurasi');
    }

    public function test_empty_openrouter_response_is_rejected(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'services.openrouter.model' => 'test/model',
        ]);
        Http::fake([
            'openrouter.test/*' => Http::response(['choices' => []]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter tidak menghasilkan respons teks.');

        app(AIService::class)->generate('Tes respons');
    }
}
