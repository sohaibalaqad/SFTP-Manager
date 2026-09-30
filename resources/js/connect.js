import { api, takeFlash } from './api';

// Saved servers live ONLY in this browser's localStorage and NEVER include a password or an SSH key
// (only which method was used, so the form opens on the right tab).
const STORAGE_KEY = 'sftp.savedServers';
const LAST_KEY = 'sftp.lastServer';
// Trusted server fingerprints ("known hosts"), per host:port. Public information, not secrets.
const KNOWN_HOSTS_KEY = 'sftp.knownHosts';
const FIELDS = ['name', 'host', 'port', 'username', 'path', 'auth'];
const MAX_KEY_SIZE = 16 * 1024;

function pick(obj) {
    const out = {};
    for (const f of FIELDS) out[f] = obj[f] ?? '';
    out.port = Number(out.port) || 22;
    out.auth = out.auth === 'key' ? 'key' : 'password';
    return out; // password, private key and passphrase are deliberately never copied
}

function load(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
}

function store(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {}
}

const hostId = (host, port) => `${String(host).trim().toLowerCase().replace(/^\[|\]$/g, '')}:${Number(port) || 22}`;

export default () => ({
    form: { name: '', host: '', port: 22, username: '', password: '', path: '', auth: 'password', privateKey: '', keyName: '', passphrase: '' },
    servers: [],
    knownHosts: {},
    remember: true,
    loading: false,
    error: '',
    notice: '',
    showPassword: false,
    pasteKey: false,
    showKnownHosts: false,

    // Host-key confirmation dialog: { status: 'unknown'|'changed', fingerprint, algorithm, expected, host }
    hostPrompt: null,

    // "Switch server" window inside the file manager (the same form in a modal).
    switchOpen: false,
    hostChecked: false,

    init() {
        this.servers = load(STORAGE_KEY, []).map(pick);
        this.knownHosts = load(KNOWN_HOSTS_KEY, {});
        const last = load(LAST_KEY, null);
        if (last) Object.assign(this.form, pick(last));
        this.error = takeFlash() || '';
        this.notice = this.$el.dataset.status || '';
        if (this.$el.dataset.error) this.error = this.$el.dataset.error;
    },

    get knownHostList() {
        return Object.entries(this.knownHosts).map(([id, h]) => ({ id, ...h }));
    },

    get currentKnownHost() {
        return this.knownHosts[hostId(this.form.host, this.form.port)] ?? null;
    },

    /** Open the switch-server window, pre-filled with a saved server (or empty for a new one). */
    openSwitch(server = null) {
        this.error = '';
        this.hostPrompt = null;
        this.servers = load(STORAGE_KEY, []).map(pick);
        this.knownHosts = load(KNOWN_HOSTS_KEY, {});
        if (server) {
            this.select(server);
        } else {
            Object.assign(this.form, { name: '', host: '', port: 22, username: '', password: '', path: '', auth: 'password', privateKey: '', keyName: '', passphrase: '' });
            this.$nextTick(() => document.getElementById('name')?.focus());
        }
        this.switchOpen = true;
    },

    select(server) {
        Object.assign(this.form, pick(server), { password: '', privateKey: '', keyName: '', passphrase: '' });
        this.error = '';
        this.$nextTick(() => (this.form.auth === 'key' ? this.$refs.keyButton : this.$refs.password)?.focus());
    },

    isSelected(server) {
        return server.host === this.form.host && server.username === this.form.username && Number(server.port) === Number(this.form.port) && server.name === this.form.name;
    },

    removeServer(index) {
        this.servers.splice(index, 1);
        store(STORAGE_KEY, this.servers);
    },

    saveServer() {
        const entry = pick(this.form);
        entry.name = entry.name.trim() || entry.host;
        const i = this.servers.findIndex((s) => s.name === entry.name);
        if (i >= 0) this.servers.splice(i, 1, entry);
        else this.servers.push(entry);
        store(STORAGE_KEY, this.servers);
    },

    // ------------------------------------------------------------ SSH key

    pickKeyFile() {
        this.$refs.keyFile.value = '';
        this.$refs.keyFile.click();
    },

    async onKeyFile(file) {
        if (!file) return;
        if (file.size > MAX_KEY_SIZE) {
            this.error = 'حجم الملف أكبر من المتوقع لمفتاح SSH.';
            return;
        }
        const text = await file.text();
        if (!/PRIVATE KEY|PuTTY-User-Key-File/.test(text)) {
            this.error = /^(ssh-|ecdsa-)/.test(text.trim()) ? 'هذا مفتاح عام (.pub). اختر ملف المفتاح الخاص (بدون .pub).' : 'الملف لا يبدو مفتاح SSH خاصًا.';
            return;
        }
        this.error = '';
        this.form.privateKey = text;
        this.form.keyName = file.name;
        this.pasteKey = false;
    },

    clearKey() {
        this.form.privateKey = '';
        this.form.keyName = '';
    },

    // ------------------------------------------------------------ known hosts

    trustHost() {
        const p = this.hostPrompt;
        if (!p) return;
        this.knownHosts = { ...this.knownHosts, [p.host]: { fingerprint: p.fingerprint, algorithm: p.algorithm, added: new Date().toISOString() } };
        store(KNOWN_HOSTS_KEY, this.knownHosts);
        this.hostPrompt = null;
        this.connect();
    },

    forgetHost(id) {
        const next = { ...this.knownHosts };
        delete next[id];
        this.knownHosts = next;
        store(KNOWN_HOSTS_KEY, next);
    },

    // ------------------------------------------------------------ connect

    async connect() {
        if (this.loading) return;
        this.error = '';
        this.notice = '';

        const useKey = this.form.auth === 'key';
        if (useKey && !this.form.privateKey.trim()) {
            this.error = 'اختر ملف مفتاح SSH الخاص أو الصقه.';
            return;
        }

        this.loading = true;
        const host = hostId(this.form.host, this.form.port);
        const payload = {
            ...pick(this.form),
            host_fingerprint: this.knownHosts[host]?.fingerprint ?? null,
            ...(useKey ? { private_key: this.form.privateKey, passphrase: this.form.passphrase } : { password: this.form.password }),
        };

        try {
            const res = await api('POST', '/connect', payload);
            Object.assign(this.form, { password: '', privateKey: '', keyName: '', passphrase: '' });
            if (this.remember) this.saveServer();
            store(LAST_KEY, pick(this.form));
            // replace(): from the file manager this loads the new server (a URL without #hash is a full load).
            window.location.replace(res.redirect);
        } catch (e) {
            this.loading = false;
            const hk = e.body?.hostKey;
            if (hk && (e.status === 428 || e.status === 409)) {
                // Nothing secret was sent to the server yet: the check happens before login.
                this.hostChecked = false;
                this.hostPrompt = { ...hk, host };
                return;
            }
            this.error = e.body?.errors ? Object.values(e.body.errors).flat()[0] || e.message : e.message;
        }
    },
});
