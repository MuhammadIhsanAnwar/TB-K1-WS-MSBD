<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AIService
{
    public function generate(string $prompt): string
    {
        $apiKey = config('services.openrouter.api_key');
        $baseUrl = rtrim(
            config('services.openrouter.base_url'),
            '/'
        );
        $model = config('services.openrouter.model');

        if (!$apiKey) {
            throw new RuntimeException(
                'OpenRouter API Key belum dikonfigurasi.'
            );
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->withHeaders([
                'HTTP-Referer' => 'https://medanvisit.neoverse.my.id',
                'X-Title' => 'MedanVisit',
            ])
            ->connectTimeout(30)
            ->timeout(config('services.openrouter.timeout', 120))
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Anda adalah MedanVisit AI, asisten wisata Kota Medan. Gunakan bahasa Indonesia yang jelas dan informatif. Jangan mengarang fakta yang tidak tersedia dalam konteks yang diberikan.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1024,
            ]);

        $response->throw();

        $answer = $response->json(
            'choices.0.message.content'
        );

        if (!is_string($answer) || trim($answer) === '') {
            throw new RuntimeException(
                'OpenRouter tidak menghasilkan respons teks.'
            );
        }

        return trim($answer);
    }
}
