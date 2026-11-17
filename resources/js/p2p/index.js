import { createClient } from '@supabase/supabase-js';
import { PeerManager } from './peer-manager.js';
import { Signaling } from './signaling.js';

const root = document.querySelector('[data-p2p-root]');
const MAX_CHAT_LENGTH = 4000;

if (root) start().catch((error) => {
    root.querySelector('[data-p2p-status]').textContent = 'Gagal inisialisasi';
    showFeedback(error.message, true);
});

async function start() {
    const status = root.querySelector('[data-p2p-status]');
    const peerList = root.querySelector('[data-peer-list]');
    const pendingList = root.querySelector('[data-pending-list]');
    const count = root.querySelector('[data-online-count]');
    const connectionList = root.querySelector('[data-connection-list]');
    const turnStatus = root.querySelector('[data-turn-status]');
    const peerId = getDeviceId(root.dataset.userId);
    const api = (path, body = null, method = 'POST') => request(path, body, method);
    let credentials = await api(root.dataset.registerUrl, { peer_id: peerId, label: 'Perangkat browser' });
    turnStatus.textContent = credentials.turn_available ? 'TURN dikonfigurasi' : 'Belum dikonfigurasi';

    const supabase = createClient(credentials.supabase_url, credentials.publishable_key, {
        accessToken: async () => credentials.access_token,
        auth: { autoRefreshToken: false, persistSession: false, detectSessionInUrl: false },
    });
    const signaling = new Signaling(supabase, peerId);
    const connectionCards = new Map();
    const peerManager = new PeerManager({
        peerId,
        iceServers: credentials.ice_servers,
        onState: (remotePeerId, state, detail) => updateConnectionState(remotePeerId, state, detail),
        onMessage: (remotePeerId, text, sentAt) => addMessage(remotePeerId, text, sentAt, false),
    });

    status.textContent = 'Menghubungkan ke jaringan...';
    const presence = supabase.channel('p2p:presence', {
        config: { private: true, presence: { key: peerId } },
    });
    presence
        .on('presence', { event: 'sync' }, renderPeers)
        .on('presence', { event: 'join' }, renderPeers)
        .on('presence', { event: 'leave' }, renderPeers);

    await signaling.subscribeInbox(async (event) => {
        if (event.event === 'connection-request') {
            await loadPending().catch((error) => showFeedback(error.message, true));
        } else if (event.event === 'connection-response') {
            await completeOutgoingConnection(event.session_id);
        }
    });

    await new Promise((resolve, reject) => {
        const timeout = window.setTimeout(() => reject(new Error('Waktu habis saat bergabung ke Presence Supabase.')), 10000);
        presence.subscribe(async (channelStatus, error) => {
            if (channelStatus === 'SUBSCRIBED') {
                window.clearTimeout(timeout);
                try {
                    await presence.track({ peer_id: peerId, user_id: credentials.user_id });
                    status.textContent = 'Online — Presence Supabase aktif';
                    resolve();
                } catch (trackError) {
                    reject(trackError);
                }
            } else if (['CHANNEL_ERROR', 'TIMED_OUT', 'CLOSED'].includes(channelStatus)) {
                window.clearTimeout(timeout);
                reject(error instanceof Error ? error : new Error(`Presence gagal: ${channelStatus}`));
            }
        });
    });

    function renderPeers() {
        const state = presence.presenceState();
        const peers = new Map();

        for (const [presenceKey, entries] of Object.entries(state)) {
            for (const entry of (Array.isArray(entries) ? entries : [entries])) {
                const id = typeof entry?.peer_id === 'string' ? entry.peer_id : presenceKey;
                if (id !== peerId && String(entry?.user_id) !== String(credentials.user_id)) peers.set(id, id);
            }
        }

        count.textContent = String(peers.size);
        peerList.replaceChildren();
        if (peers.size === 0) {
            peerList.append(emptyItem('Belum ada perangkat dari akun lain yang online.'));
            return;
        }

        for (const id of peers.keys()) {
            const item = document.createElement('li');
            item.className = 'p2p-peer';
            const identity = document.createElement('span');
            identity.textContent = `Peer ${id.slice(0, 8)}`;
            const connect = document.createElement('button');
            connect.type = 'button';
            connect.textContent = 'Minta koneksi';
            connect.addEventListener('click', () => requestConnection(id, connect));
            item.append(identity, connect);
            peerList.append(item);
        }
    }

    async function requestConnection(targetPeerId, button) {
        button.disabled = true;
        try {
            const url = root.dataset.connectUrl.replace('__PEER__', encodeURIComponent(targetPeerId));
            const response = await api(url, { peer_id: peerId });
            await signaling.sendInbox(targetPeerId, 'connection-request', response.session_id);
            showFeedback(`Permintaan koneksi dikirim ke peer ${targetPeerId.slice(0, 8)}.`);
        } catch (error) {
            showFeedback(error.message, true);
        } finally {
            button.disabled = false;
        }
    }

    async function loadPending() {
        const response = await api(`${root.dataset.pendingUrl}?peer_id=${encodeURIComponent(peerId)}`, null, 'GET');
        pendingList.replaceChildren();
        if (response.connections.length === 0) {
            pendingList.append(emptyItem('Tidak ada permintaan koneksi yang menunggu.'));
            return;
        }

        for (const connection of response.connections) {
            const item = document.createElement('li');
            item.className = 'p2p-peer';
            const text = document.createElement('span');
            text.textContent = `Permintaan dari peer ${connection.requester_peer_id.slice(0, 8)}`;
            const actions = document.createElement('div');
            actions.className = 'p2p-actions';
            const accept = document.createElement('button');
            accept.type = 'button';
            accept.textContent = 'Terima';
            accept.addEventListener('click', () => answerConnection(connection, true, accept));
            const reject = document.createElement('button');
            reject.type = 'button';
            reject.className = 'p2p-button--secondary';
            reject.textContent = 'Tolak';
            reject.addEventListener('click', () => answerConnection(connection, false, reject));
            actions.append(accept, reject);
            item.append(text, actions);
            pendingList.append(item);
        }
    }

    async function answerConnection(connection, accept, button) {
        button.disabled = true;
        try {
            const response = await api(
                root.dataset.respondUrl.replace('__SESSION__', encodeURIComponent(connection.id)).replace('__DECISION__', accept ? 'accept' : 'reject'),
                { peer_id: peerId },
            );
            if (accept) await startConnection(response, false);
            await signaling.sendInbox(connection.requester_peer_id, 'connection-response', connection.id);
            await loadPending();
            showFeedback(accept ? 'Permintaan diterima; membangun koneksi WebRTC.' : 'Permintaan koneksi ditolak.');
        } catch (error) {
            button.disabled = false;
            showFeedback(error.message, true);
        }
    }

    async function completeOutgoingConnection(sessionId) {
        try {
            const connection = await api(
                `${root.dataset.connectionUrl.replace('__SESSION__', encodeURIComponent(sessionId))}?peer_id=${encodeURIComponent(peerId)}`,
                null,
                'GET',
            );
            if (connection.status === 'accepted') await startConnection(connection, true);
            else showFeedback('Permintaan koneksi ditolak.');
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    async function startConnection(connection, initiator) {
        const remotePeerId = initiator ? connection.target_peer_id : connection.requester_peer_id;
        if (connectionCards.has(connection.id)) return;
        createConnectionCard(connection.id, remotePeerId);

        try {
            await peerManager.start({
                sessionId: connection.id,
                remotePeerId,
                initiator,
                sendSignal: (...args) => signaling.sendSignal(...args),
                onSignal: (handler) => signaling.subscribeSession(connection.id, (payload) => {
                    if (payload.from_peer_id !== remotePeerId) {
                        showFeedback('Signaling dari peer yang tidak sesuai dengan sesi ini ditolak.', true);
                        return;
                    }
                    handler(payload.message).catch((error) => showFeedback(error.message, true));
                }),
            });
        } catch (error) {
            peerManager.close(connection.id);
            showFeedback(error.message, true);
        }
    }

    function createConnectionCard(sessionId, remotePeerId) {
        const card = document.createElement('article');
        card.className = 'p2p-chat';
        const title = document.createElement('h3');
        title.textContent = `Peer ${remotePeerId.slice(0, 8)}`;
        const state = document.createElement('p');
        state.className = 'p2p-chat__state';
        state.textContent = 'Menginisialisasi WebRTC';
        const messages = document.createElement('ol');
        messages.className = 'p2p-chat__messages';
        messages.setAttribute('aria-live', 'polite');
        const form = document.createElement('form');
        form.className = 'p2p-chat__form';
        const input = document.createElement('input');
        input.type = 'text';
        input.maxLength = MAX_CHAT_LENGTH;
        input.placeholder = 'Pesan dikirim langsung lewat DataChannel';
        input.setAttribute('aria-label', `Pesan untuk peer ${remotePeerId.slice(0, 8)}`);
        const send = document.createElement('button');
        send.type = 'submit';
        send.textContent = 'Kirim P2P';
        form.append(input, send);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const text = input.value.trim();
            if (!text) return;
            try {
                peerManager.send(sessionId, text);
                addMessage(remotePeerId, text, new Date().toISOString(), true);
                input.value = '';
            } catch (error) {
                showFeedback(error.message, true);
            }
        });

        card.append(title, state, messages, form);
        connectionList.prepend(card);
        connectionCards.set(sessionId, { remotePeerId, card, state, messages });
    }

    function updateConnectionState(remotePeerId, value, detail) {
        for (const connection of connectionCards.values()) {
            if (connection.remotePeerId === remotePeerId) {
                connection.state.textContent = `${value} · ${detail}`;
            }
        }
    }

    function addMessage(remotePeerId, text, sentAt, outgoing) {
        const connection = [...connectionCards.values()].find((item) => item.remotePeerId === remotePeerId);
        if (!connection) return;
        const item = document.createElement('li');
        const time = document.createElement('time');
        const parsed = Date.parse(sentAt);
        if (!Number.isNaN(parsed)) {
            time.dateTime = new Date(parsed).toISOString();
            time.textContent = new Date(parsed).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        }
        const content = document.createElement('span');
        content.textContent = `${outgoing ? 'Saya' : `Peer ${remotePeerId.slice(0, 8)}`}: ${text}`;
        item.append(time, content);
        connection.messages.append(item);
        connection.messages.scrollTop = connection.messages.scrollHeight;
    }

    function emptyItem(text) {
        const item = document.createElement('li');
        item.className = 'p2p-empty';
        item.textContent = text;
        return item;
    }

    function showFeedback(message, isError = false) {
        const feedback = root.querySelector('[data-p2p-feedback]');
        feedback.textContent = message;
        feedback.dataset.kind = isError ? 'error' : 'info';
    }

    await loadPending().catch((error) => showFeedback(error.message, true));
    if (!credentials.turn_available) {
        showFeedback('TURN belum dikonfigurasi. Koneksi langsung dapat berjalan, tetapi beberapa NAT/firewall memerlukan TURN.');
    }

    const refresh = window.setInterval(async () => {
        try {
            const renewed = await api(root.dataset.tokenUrl, { peer_id: peerId });
            credentials = { ...credentials, ...renewed };
            turnStatus.textContent = renewed.turn_available ? 'TURN dikonfigurasi' : 'Belum dikonfigurasi';
            peerManager.iceServers = renewed.ice_servers;
            await supabase.realtime.setAuth(renewed.access_token);
        } catch (error) {
            status.textContent = 'Token Realtime gagal diperbarui; koneksi baru tidak tersedia.';
            showFeedback(error.message, true);
        }
    }, 240000);

    window.addEventListener('pagehide', () => {
        window.clearInterval(refresh);
        presence.untrack();
        [...peerManager.connections.keys()].forEach((sessionId) => peerManager.close(sessionId));
        signaling.close();
        supabase.removeChannel(presence);
    }, { once: true });

    function request(path, body, method) {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        };
        if (body !== null) headers['Content-Type'] = 'application/json';

        return fetch(path, {
            method,
            headers,
            credentials: 'same-origin',
            ...(body === null ? {} : { body: JSON.stringify(body) }),
        }).then(async (response) => {
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Permintaan gagal (${response.status}).`);
            return result;
        });
    }

    function getDeviceId(userId) {
        const key = `medanvisit.p2p.peer_id.${userId}`;
        let id = localStorage.getItem(key);
        if (!id) {
            id = crypto.randomUUID();
            localStorage.setItem(key, id);
        }
        return id;
    }
}
