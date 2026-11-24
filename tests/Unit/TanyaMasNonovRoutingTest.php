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

    public function test_ticket_price_question_routes_to_tourism_data(): void
    {
        $routing = TanyaMasNonovService::klasifikasi('Berapa harga tiket Istana Maimun?');

        $this->assertSame('sparql', $routing['source']);
        $this->assertTrue($routing['requires_database']);
    }

    public function test_review_and_like_rankings_route_to_operational_and_semantic_data(): void
    {
        $routing = TanyaMasNonovService::klasifikasi('Mana tempat yang paling banyak diulas dan banyak dilike?');

        $this->assertSame('tourism_popularity', $routing['intent']);
        $this->assertSame('hybrid', $routing['source']);
        $this->assertTrue($routing['requires_database']);
    }

    public function test_place_rating_questions_use_the_operational_data_route(): void
    {
        $routing = TanyaMasNonovService::klasifikasi('Berapa rating dan jumlah suka Istana Maimun?');

        $this->assertSame('tourism_popularity', $routing['intent']);
        $this->assertSame('hybrid', $routing['source']);
    }

    public function test_nearby_recommendations_keep_the_geographic_route(): void
    {
        $routing = TanyaMasNonovService::klasifikasi('Rekomendasikan wisata terdekat dari Istana Maimun');

        $this->assertSame('tourism_knowledge', $routing['intent']);
        $this->assertSame('sparql', $routing['source']);
    }

    public function test_food_recommendations_route_to_tourism_data(): void
    {
        $routing = TanyaMasNonovService::klasifikasi('Tempat makan enak');

        $this->assertSame('tourism_knowledge', $routing['intent']);
        $this->assertSame('sparql', $routing['source']);
        $this->assertTrue($routing['requires_database']);
    }
}
