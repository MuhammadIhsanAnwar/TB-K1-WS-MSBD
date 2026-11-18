<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Logo dimuat dari URL publik di template, sama seperti email reset password. */
class VerifikasiEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Konfirmasi email MedanVisit'
        );
    }

    public function content(): Content
    {
        // versi teks biasa ikut dikirim (multipart/alternative): email hanya-HTML lebih mudah masuk spam
        return new Content(
            view: 'emails.verifikasi-email'
        );
    }
}
