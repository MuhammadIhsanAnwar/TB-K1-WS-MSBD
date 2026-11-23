<?php

namespace Tests\Unit;

use App\Services\AIService;
use App\Services\PostgreSQLService;
use App\Services\TanyaMasNonovService;
use Mockery;
use Tests\TestCase;

class TanyaMasNonovRoutingTest extends TestCase
{
    public function test_transaction_count_answer_uses_the_total_count(): void
    {
        config(['services.openrouter.api_key' => null]);
        $ai = Mockery::mock(AIService::class);
        $postgres = Mockery::mock(PostgreSQLService::class);
        $postgres->shouldReceive('jumlahPesanan')->once()->with(42)->andReturn(12);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Berapa jumlah transaksi saya?', ['id' => 42]);

        $this->assertStringContainsString('12', $jawaban['teks'][0]);
    }

    public function test_private_transaction_questions_require_an_authenticated_user(): void
    {
        $ai = Mockery::mock(AIService::class);
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Apa status transaksi saya?');

        $this->assertStringContainsString('silakan masuk', $jawaban['teks'][0]);
    }

    public function test_it_routes_user_data_questions_to_sql(): void
    {
        $this->assertSame(
            ['intent' => 'user_transaction_history', 'source' => 'sql', 'requires_database' => true],
            TanyaMasNonovService::klasifikasi('Berapa jumlah transaksi saya?'),
        );
    }

    public function test_it_routes_personalized_recommendations_to_both_databases(): void
    {
        $this->assertSame(
            ['intent' => 'personalized_recommendation', 'source' => 'hybrid', 'requires_database' => true],
            TanyaMasNonovService::klasifikasi('Wisata apa yang sesuai dengan preferensi saya?'),
        );
    }

    public function test_it_routes_tourism_and_general_questions_separately(): void
    {
        $this->assertSame('sparql', TanyaMasNonovService::klasifikasi('Apa saja taman di Kota Medan?')['source']);
        $this->assertSame('general', TanyaMasNonovService::klasifikasi('Apa itu Web Semantik?')['source']);
    }
}
