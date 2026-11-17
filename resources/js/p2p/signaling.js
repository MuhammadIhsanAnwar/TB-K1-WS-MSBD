const SUBSCRIBE_TIMEOUT_MS = 10000;

export class Signaling {
    constructor(client, peerId) {
        this.client = client;
        this.peerId = peerId;
        this.inbox = null;
        this.sessions = new Map();
    }

    async subscribeInbox(onConnectionEvent) {
        const channel = this.client.channel(`p2p:inbox:${this.peerId}`, {
            config: { private: true },
        });
        channel.on('broadcast', { event: 'connection' }, ({ payload }) => {
            if (payload && typeof payload.session_id === 'string') {
                onConnectionEvent(payload);
            }
        });

        try {
            await this.subscribe(channel);
        } catch (error) {
            await this.client.removeChannel(channel);
            throw error;
        }
        this.inbox = channel;
        return channel;
    }

    async subscribeSession(sessionId, onSignal) {
        if (this.sessions.has(sessionId)) return;

        const channel = this.client.channel(`p2p:session:${sessionId}`, {
            config: { private: true },
        });
        channel.on('broadcast', { event: 'signal' }, ({ payload }) => {
            if (
                payload
                && payload.session_id === sessionId
                && payload.message
                && typeof payload.from_peer_id === 'string'
                && JSON.stringify(payload.message).length <= 131072
            ) {
                onSignal(payload);
            }
        });

        try {
            await this.subscribe(channel);
        } catch (error) {
            await this.client.removeChannel(channel);
            throw error;
        }
        this.sessions.set(sessionId, channel);
    }

    async sendInbox(targetPeerId, event, sessionId) {
        const channel = this.client.channel(`p2p:inbox:${targetPeerId}`, {
            config: { private: true },
        });

        try {
            await this.subscribe(channel);
            const result = await channel.send({
                type: 'broadcast',
                event: 'connection',
                payload: { event, session_id: sessionId },
            });
            if (result !== 'ok') throw new Error('Supabase menolak pesan signaling koneksi.');
        } finally {
            await this.client.removeChannel(channel);
        }
    }

    async sendSignal(sessionId, fromPeerId, message) {
        const channel = this.sessions.get(sessionId);
        if (!channel) throw new Error('Kanal signaling WebRTC belum terhubung.');

        const result = await channel.send({
            type: 'broadcast',
            event: 'signal',
            payload: { session_id: sessionId, from_peer_id: fromPeerId, message },
        });
        if (result !== 'ok') throw new Error('Supabase menolak pesan signaling WebRTC.');
    }

    async close() {
        const channels = [...this.sessions.values()];
        if (this.inbox) channels.push(this.inbox);
        this.sessions.clear();
        this.inbox = null;
        await Promise.all(channels.map((channel) => this.client.removeChannel(channel)));
    }

    subscribe(channel) {
        return new Promise((resolve, reject) => {
            const timeout = window.setTimeout(() => {
                reject(new Error('Waktu habis saat bergabung ke kanal signaling Supabase.'));
            }, SUBSCRIBE_TIMEOUT_MS);

            channel.subscribe((status, error) => {
                if (status === 'SUBSCRIBED') {
                    window.clearTimeout(timeout);
                    resolve();
                } else if (['CHANNEL_ERROR', 'TIMED_OUT', 'CLOSED'].includes(status)) {
                    window.clearTimeout(timeout);
                    reject(error instanceof Error ? error : new Error(`Kanal signaling gagal: ${status}`));
                }
            });
        });
    }
}
