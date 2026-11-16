<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BalasanPesanAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $idPesan,
        public readonly string $namaPengguna,
        public readonly string $namaAdmin,
        public readonly string $kategori,
        public readonly ?string $namaTempat,
        public readonly string $pesanAwal,
        public readonly string $balasan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Balasan pesan MedanVisit #'.$this->idPesan);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.balasan-pesan-admin');
    }
}
