<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordEmail;
use App\Mail\VerifikasiEmail;
use Tests\TestCase;

class EmailLogoTest extends TestCase
{
    public function test_account_emails_embed_the_logo_instead_of_relying_on_a_public_url(): void
    {
        $verification = (new VerifikasiEmail('https://medanvisit.test/verify'))->render();
        $passwordReset = (new ResetPasswordEmail('https://medanvisit.test/reset'))->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $verification);
        $this->assertStringContainsString('src="data:image/png;base64,', $passwordReset);
        $this->assertStringContainsString('Selamat datang di MedanVisiit', $verification);
        $this->assertStringNotContainsString('JELAJAHI KOTA MEDAN', $verification);
        $this->assertStringNotContainsString('JELAJAHI KOTA MEDAN', $passwordReset);
        $this->assertStringNotContainsString('storage/logo_app/png/MedanVisit_logo_horizontal_terang.png', $verification);
        $this->assertStringNotContainsString('storage/logo_app/png/MedanVisit_logo_horizontal_terang.png', $passwordReset);
    }
}
