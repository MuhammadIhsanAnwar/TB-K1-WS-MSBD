const MAX_MESSAGE_BYTES = 16 * 1024;
const MAX_BUFFERED_BYTES = 256 * 1024;
const CONNECTION_TIMEOUT_MS = 45000;

export class PeerManager {
    constructor({ peerId, iceServers, onState, onMessage }) {
        this.peerId = peerId;
        this.iceServers = iceServers;
        this.onState = onState;
        this.onMessage = onMessage;
        this.connections = new Map();
    }

    async start({ sessionId, remotePeerId, initiator, sendSignal, onSignal }) {
        if (this.connections.has(sessionId)) return;

        const pc = new RTCPeerConnection({ iceServers: this.iceServers });
        const connection = {
            pc,
            remotePeerId,
            channel: null,
            sendSignal,
            pendingCandidates: [],
            pendingIceComplete: false,
            timeout: window.setTimeout(() => {
                if (connection.channel?.readyState !== 'open') {
                    this.onState(remotePeerId, 'failed', 'Tidak tersambung; periksa jaringan atau TURN.');
                }
            }, CONNECTION_TIMEOUT_MS),
        };
        this.connections.set(sessionId, connection);

        pc.onicecandidate = ({ candidate }) => {
            const message = candidate
                ? { type: 'ice_candidate', candidate: candidate.toJSON() }
                : { type: 'ice_complete' };
            this.signal(sessionId, message)
                .catch((error) => this.onState(remotePeerId, 'failed', error.message));
        };
        pc.ondatachannel = ({ channel }) => {
            if (channel.label !== 'medanvisit-chat') {
                channel.close();
                this.onState(remotePeerId, 'failed', 'Peer membuka kanal data yang tidak didukung.');
                return;
            }
            this.attachDataChannel(sessionId, channel);
        };
        pc.onconnectionstatechange = () => {
            this.onState(remotePeerId, pc.connectionState, this.connectionPath(pc));
            if (['failed', 'closed'].includes(pc.connectionState)) window.clearTimeout(connection.timeout);
        };
        pc.oniceconnectionstatechange = () => {
            if (pc.iceConnectionState === 'failed') {
                this.onState(remotePeerId, 'failed', 'ICE gagal; jaringan ini mungkin memerlukan TURN.');
            }
        };

        if (initiator) this.attachDataChannel(sessionId, pc.createDataChannel('medanvisit-chat', { ordered: true }));

        try {
            await onSignal((signal) => this.receiveSignal(sessionId, signal));
            if (initiator) {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                await this.signal(sessionId, { type: 'offer', description: pc.localDescription.toJSON() });
            }
            this.onState(remotePeerId, 'connecting', 'Menunggu negosiasi WebRTC');
        } catch (error) {
            this.close(sessionId);
            throw error;
        }
    }

    send(sessionId, text) {
        const connection = this.connections.get(sessionId);
        const channel = connection?.channel;
        if (!channel || channel.readyState !== 'open') throw new Error('RTCDataChannel belum terbuka.');

        const payload = JSON.stringify({ type: 'chat', text, sent_at: new Date().toISOString() });
        if (new TextEncoder().encode(payload).byteLength > MAX_MESSAGE_BYTES) {
            throw new Error('Pesan melebihi batas 16 KB.');
        }
        if (channel.bufferedAmount > MAX_BUFFERED_BYTES) {
            throw new Error('Antrean DataChannel penuh. Tunggu sebentar lalu kirim kembali.');
        }

        channel.send(payload);
    }

    close(sessionId) {
        const connection = this.connections.get(sessionId);
        if (!connection) return;
        window.clearTimeout(connection.timeout);
        connection.channel?.close();
        connection.pc.close();
        this.connections.delete(sessionId);
    }

    attachDataChannel(sessionId, channel) {
        const connection = this.connections.get(sessionId);
        if (!connection) {
            channel.close();
            return;
        }

        connection.channel = channel;
        channel.onopen = () => {
            window.clearTimeout(connection.timeout);
            this.onState(connection.remotePeerId, 'connected', this.connectionPath(connection.pc));
        };
        channel.onclose = () => this.onState(connection.remotePeerId, 'disconnected', 'RTCDataChannel ditutup');
        channel.onerror = () => this.onState(connection.remotePeerId, 'failed', 'RTCDataChannel mengalami kesalahan.');
        channel.onmessage = ({ data }) => {
            if (typeof data !== 'string' || new TextEncoder().encode(data).byteLength > MAX_MESSAGE_BYTES) {
                this.onState(connection.remotePeerId, 'failed', 'Pesan peer tidak valid atau terlalu besar.');
                return;
            }

            let message;
            try {
                message = JSON.parse(data);
            } catch {
                this.onState(connection.remotePeerId, 'failed', 'Pesan peer bukan JSON yang valid.');
                return;
            }
            if (message?.type !== 'chat' || typeof message.text !== 'string' || message.text.length > MAX_MESSAGE_BYTES) {
                this.onState(connection.remotePeerId, 'failed', 'Format pesan peer tidak didukung.');
                return;
            }

            this.onMessage(connection.remotePeerId, message.text, message.sent_at);
        };
    }

    async receiveSignal(sessionId, signal) {
        const connection = this.connections.get(sessionId);
        if (!connection || !signal || typeof signal.type !== 'string') return;
        const { pc } = connection;

        if (signal.type === 'offer' && signal.description?.type === 'offer') {
            await pc.setRemoteDescription(signal.description);
            await this.flushCandidates(connection);
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await this.signal(sessionId, { type: 'answer', description: pc.localDescription.toJSON() });
            return;
        }
        if (signal.type === 'answer' && signal.description?.type === 'answer') {
            await pc.setRemoteDescription(signal.description);
            await this.flushCandidates(connection);
            return;
        }
        if (signal.type === 'ice_candidate' && signal.candidate?.candidate) {
            const candidate = new RTCIceCandidate(signal.candidate);
            if (pc.remoteDescription) await pc.addIceCandidate(candidate);
            else connection.pendingCandidates.push(candidate);
            return;
        }
        if (signal.type === 'ice_complete') {
            if (pc.remoteDescription) await pc.addIceCandidate(null);
            else connection.pendingIceComplete = true;
        }
    }

    async flushCandidates(connection) {
        for (const candidate of connection.pendingCandidates) {
            await connection.pc.addIceCandidate(candidate);
        }
        connection.pendingCandidates = [];
        if (connection.pendingIceComplete) {
            await connection.pc.addIceCandidate(null);
            connection.pendingIceComplete = false;
        }
    }

    signal(sessionId, message) {
        const connection = this.connections.get(sessionId);
        return connection.sendSignal(sessionId, this.peerId, message);
    }

    connectionPath(pc) {
        if (pc.connectionState !== 'connected') return 'Menegosiasikan jalur jaringan';

        pc.getStats().then((stats) => {
            let selectedPair;
            stats.forEach((report) => {
                if (report.type === 'transport' && report.selectedCandidatePairId) {
                    selectedPair = stats.get(report.selectedCandidatePairId);
                } else if (report.type === 'candidate-pair' && report.nominated && report.state === 'succeeded') {
                    selectedPair = report;
                }
            });
            if (!selectedPair) return;

            const local = stats.get(selectedPair.localCandidateId);
            const remote = stats.get(selectedPair.remoteCandidateId);
            const relay = local?.candidateType === 'relay' || remote?.candidateType === 'relay';
            this.onState(
                this.remotePeerIdFor(pc),
                'connected',
                relay ? 'Relay (TURN)' : 'Direct',
            );
        }).catch(() => {
            this.onState(this.remotePeerIdFor(pc), 'connected', 'Terhubung; jenis jalur jaringan tidak tersedia.');
        });

        return 'Terhubung';
    }

    remotePeerIdFor(pc) {
        for (const connection of this.connections.values()) {
            if (connection.pc === pc) return connection.remotePeerId;
        }
        return '';
    }
}
