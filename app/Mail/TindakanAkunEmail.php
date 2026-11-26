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
        public readonly string $url,
        public readonly string $tindakan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Konfirmasi tindakan akun MedanVisit');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tindakan-akun',
            text: 'emails.tindakan-akun-teks',
        );
    }
}
