<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TindakanAkunEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $kode,
        public readonly string $tindakan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kode verifikasi tindakan akun MedanVisit');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tindakan-akun',
            text: 'emails.tindakan-akun-teks',
        );
    }
}
