@extends('layouts.situs')
@section('judul', 'MedanVisit Hub')
@section('deskripsi', 'Temukan peer MedanVisit dan bertukar pesan langsung melalui WebRTC DataChannel.')
@section('tanpa-kaki', '1')
@section('tanpa-kepala', '1')

@section('isi')
<div class="p2p halaman-alat"
     data-p2p-root
     data-user-id="{{ session('pengguna.id') }}"
     data-register-url="{{ route('api.p2p.peers.register') }}"
     data-token-url="{{ route('api.p2p.token') }}"
     data-connect-url="{{ route('api.p2p.peers.connect', ['peer' => '__PEER__']) }}"
     data-pending-url="{{ route('api.p2p.connections.pending') }}"
     data-connection-url="{{ route('api.p2p.connections.show', ['session' => '__SESSION__']) }}"
     data-respond-url="{{ route('api.p2p.connections.respond', ['session' => '__SESSION__', 'decision' => '__DECISION__']) }}">
    <header class="p2p__heading">
        <div>
            <p class="p2p__eyebrow">MedanVisit Hub</p>
            <h1>Komunikasi peer-to-peer</h1>
            <p>Temukan perangkat online, terima permintaan koneksi, lalu bertukar pesan melalui RTCDataChannel.</p>
        </div>
        <a class="p-btn" href="{{ route('akun') }}">Kembali ke akun</a>
    </header>

    <p class="p2p__feedback" data-p2p-feedback role="status" aria-live="polite">Menyiapkan perangkat...</p>

    <section class="p2p__metrics" aria-label="Status jaringan">
        <div><span>Status jaringan</span><strong data-p2p-status>Menginisialisasi</strong></div>
        <div><span>Peer aktif lain</span><strong data-online-count>0</strong></div>
        <div><span>Transport pesan</span><strong>WebRTC DataChannel</strong></div>
        <div><span>Relay NAT</span><strong>{{ config('services.turn.urls') ? 'TURN dikonfigurasi' : 'Belum dikonfigurasi' }}</strong></div>
    </section>

    <div class="p2p__columns">
        <section class="p2p__panel" aria-labelledby="p2p-peers-heading">
            <header><h2 id="p2p-peers-heading">Peer online</h2><span>Presence Realtime</span></header>
            <ul class="p2p__list" data-peer-list>
                <li class="p2p-empty">Memuat daftar peer...</li>
            </ul>
        </section>
        <section class="p2p__panel" aria-labelledby="p2p-pending-heading">
            <header><h2 id="p2p-pending-heading">Permintaan masuk</h2><span>Perlu persetujuan</span></header>
            <ul class="p2p__list" data-pending-list>
                <li class="p2p-empty">Memuat permintaan...</li>
            </ul>
        </section>
    </div>

    <section class="p2p__panel p2p__connections" aria-labelledby="p2p-connections-heading">
        <header><h2 id="p2p-connections-heading">Koneksi dan pesan</h2><span>Isi pesan tidak melewati Laravel atau Supabase</span></header>
        <div class="p2p__connection-list" data-connection-list>
            <p class="p2p-empty">Belum ada koneksi. Pilih peer online lalu tunggu permintaan diterima.</p>
        </div>
    </section>

    <p class="p2p__note">Supabase hanya dipakai untuk Presence dan pertukaran signaling (SDP/ICE). Pesan chat dikirim langsung antara browser melalui DataChannel. Data dari peer lain tetap harus dianggap tidak tepercaya.</p>
</div>
@endsection
