<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordEmail;
use App\Mail\VerifikasiEmail;
use Tests\TestCase;

class EmailLogoTest extends TestCase
{
    public function test_account_emails_reference_the_public_logo_without_embedding_it(): void
    {
        $verification = (new VerifikasiEmail('https://medanvisit.test/verify'))->render();
        $passwordReset = (new ResetPasswordEmail('https://medanvisit.test/reset'))->render();
        $logo = '/storage/logo_app/png/MedanVisit_logo_horizontal_gelap.png';

        $this->assertStringContainsString($logo, $verification);
        $this->assertStringContainsString($logo, $passwordReset);
        $this->assertMatchesRegularExpression('/src="https?:\/\/[^"]+\/storage\/logo_app\/png\/MedanVisit_logo_horizontal_gelap\.png"/', $verification);
        $this->assertMatchesRegularExpression('/src="https?:\/\/[^"]+\/storage\/logo_app\/png\/MedanVisit_logo_horizontal_gelap\.png"/', $passwordReset);
        $this->assertStringNotContainsString('data:image/png;base64,', $verification);
        $this->assertStringNotContainsString('data:image/png;base64,', $passwordReset);
        $this->assertStringNotContainsString('cid:', $verification);
        $this->assertStringNotContainsString('cid:', $passwordReset);
        $this->assertStringContainsString('Selamat datang di MedanVisiit', $verification);
        $this->assertStringNotContainsString('JELAJAHI KOTA MEDAN', $verification);
        $this->assertStringNotContainsString('JELAJAHI KOTA MEDAN', $passwordReset);
    }
}
