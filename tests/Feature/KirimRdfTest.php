<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KirimRdfTest extends TestCase
{
    public function test_rdf_command_rejects_empty_database3_credentials_before_sending_request(): void
    {
        $this->app['env'] = 'production';
        config([
            'services.sparql.statements_endpoint' => 'https://sparql.test/repositories/medanvisit/statements',
            'services.sparql.username' => '',
            'services.sparql.password' => '',
        ]);
        Http::fake();

        $exitCode = Artisan::call('rdf:kirim');

        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }
}
