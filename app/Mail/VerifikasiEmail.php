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
            if (!is_file($path)) {
                throw new \RuntimeException(
                    'File logo tidak ditemukan: ' . $path
                );
            }

            $logo = DataPart::fromPath(
                $path,
                'MedanVisit_logo_horizontal_terang.png',
                'image/png'
            );

            $logo->asInline();
            $logo->setContentId('medanvisit-logo@medanvisit.local');

            $message->addPart($logo);
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Konfirmasi email MedanVisit'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verifikasi-email'
        );
    }
}
