import { api, ApiError, uploadWithProgress, takeFlash } from './api';
import { toggleTheme, currentTheme } from './theme';
import localPane from './local-pane';
import { drag, dragKind, formatDate, formatSize, hexId, iso, joinRel, parentRel, randomId, REMOTE_TYPE } from './utils';

// Objects that must NOT be wrapped in Alpine's reactive proxies.
let editor = null;
let dialogResolve = null;
const uploadFiles = new Map(); // upload id -> File
const uploadXhrs = new Map(); // upload id -> XMLHttpRequest
let logTimer = null;
// Folder listings already seen: shown instantly when revisiting, then refreshed in the background.
const listCache = new Map(); // path -> items
let loadSeq = 0; // ignore responses of folders the user already navigated away from
let refreshTimer = null;

// ---------------------------------------------------------------- folder watch (new-file alerts)
const WATCH_KEY = 'sftp.watch'; // per server: { path, interval, sound } — non-sensitive, this browser only
const WATCH_INTERVALS = [15, 30, 60, 300];
// Temporary names used by upload tools while a file is still arriving: not "new files" yet.
const TEMP_NAME = /(\.part|\.filepart|\.crdownload|\.tmp|~)$/i;
let watchTimer = null;
let watchKnown = null; // Map name -> { size, mtime } of the watched folder at the last check
let watchBusy = false;
let baseTitle = document.title;

function watchPrefs() {
    try {
        return JSON.parse(localStorage.getItem(WATCH_KEY)) ?? {};
    } catch {
        return {};
    }
}

/** Short, soft two-tone chime (no audio file needed). */
function chime() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [[880, 0], [1320, 0.16]].forEach(([freq, at]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
            gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.3);
            osc.connect(gain).connect(ctx.destination);
            osc.start(ctx.currentTime + at);
            osc.stop(ctx.currentTime + at + 0.32);
        });
        setTimeout(() => ctx.close(), 1000);
    } catch {}
}

const ACTIVE = ['queued', 'waiting', 'uploading', 'downloading', 'retrying', 'working'];

// Archives handled by the app (others, like .rar/.7z, show a clear "not supported" message).
const OPENABLE_ARCHIVE = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2?)$/i;
const archiveBase = (name) => name.replace(/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2?)$/i, '');
const PHASES = { download: 'تنزيل من السيرفر', extract: 'استخراج', compress: 'ضغط', upload: 'رفع إلى السيرفر', send: 'إرسال إلى جهازك' };

// ---------------------------------------------------------------- resumable uploads
// Automatic retries after a network drop (seconds to wait before each attempt). Waiting while the
// browser is offline doesn't use up attempts. After the last one the upload is paused (resumable).
const RETRY_DELAYS = [2, 4, 8, 15, 30, 30, 30, 30];
const RETRYABLE = [0, 408, 429, 502, 503, 504];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Unfinished uploads are remembered in this browser (names/sizes only, never file contents) so
// choosing the same file again — even after closing the browser — continues where it stopped.
const PENDING_KEY = 'sftp.pendingUploads';
const PENDING_TTL = 7 * 24 * 3600 * 1000;
function pendingAll() {
    try {
        const all = JSON.parse(localStorage.getItem(PENDING_KEY)) ?? {};
        for (const [k, v] of Object.entries(all)) if (Date.now() - v.saved > PENDING_TTL) delete all[k];
        return all;
    } catch {
        return {};
    }
}
function pendingWrite(all) {
    try {
        localStorage.setItem(PENDING_KEY, JSON.stringify(all));
    } catch {}
}
const serverId = (server) => `${server.username}@${server.host}:${server.port}`;
const pendingKey = (server, dir, name) => `${serverId(server)}|${dir}|${name}`;
const MAX_DROP_FILES = 5000;

/**
 * Read dropped files and folders (recursively) from DataTransfer entries.
 * @returns {Promise<{ files: {file: File, rel: string}[], dirs: string[] }>}
 */
async function collectEntries(entries) {
    const files = [];
    const dirs = [];
    const readAll = (reader) =>
        new Promise((resolve, reject) => {
            const out = [];
            const next = () => reader.readEntries((batch) => (batch.length ? (out.push(...batch), next()) : resolve(out)), reject);
            next();
        });
    const walk = async (entry, prefix) => {
        if (files.length >= MAX_DROP_FILES) throw new Error(`لا يمكن رفع أكثر من ${MAX_DROP_FILES} ملف دفعة واحدة.`);
        const rel = joinRel(prefix, entry.name);
        if (entry.isFile) {
            files.push({ file: await new Promise((resolve, reject) => entry.file(resolve, reject)), rel });
        } else if (entry.isDirectory) {
            dirs.push(rel);
            for (const child of await readAll(entry.createReader())) await walk(child, rel);
        }
    };
    for (const entry of entries) await walk(entry, '');
    return { files, dirs };
}

function loadNotifyPref() {
    try {
        return localStorage.getItem('sftp.desktopNotify') === '1' && 'Notification' in window && Notification.permission === 'granted';
    } catch {
        return false;
    }
}

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
const CODE_EXT = ['php', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'css', 'scss', 'html', 'htm', 'vue', 'json', 'xml', 'yml', 'yaml', 'sh', 'py', 'sql', 'conf', 'ini', 'env'];
const TEXT_EXT = ['txt', 'md', 'log', 'csv'];
const ARCHIVE_EXT = ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'xz'];
const TYPE_LABELS = {
    php: 'PHP', js: 'JavaScript', ts: 'TypeScript', css: 'CSS', scss: 'SCSS', html: 'HTML', htm: 'HTML',
    json: 'JSON', xml: 'XML', yml: 'YAML', yaml: 'YAML', md: 'Markdown', txt: 'نص', log: 'سجل',
    env: 'إعدادات', conf: 'إعدادات', ini: 'إعدادات', sh: 'Shell', py: 'Python', sql: 'SQL', pdf: 'PDF',
    jpg: 'صورة JPEG', jpeg: 'صورة JPEG', png: 'صورة PNG', gif: 'صورة GIF', webp: 'صورة WebP', svg: 'صورة SVG',
    zip: 'أرشيف ZIP', gz: 'أرشيف GZ', tar: 'أرشيف TAR',
};

const FILE_BASE = '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>';
const ROW_ICONS = {
    folder: '<path fill="currentColor" fill-opacity=".18" d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    file: FILE_BASE,
    code: FILE_BASE + '<path d="m10 13-2 2 2 2"/><path d="m14 17 2-2-2-2"/>',
    text: FILE_BASE + '<path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
    pdf: FILE_BASE + '<path d="M8 13h2a1.5 1.5 0 0 1 0 3H8v-3Zm0 3v2"/><path d="M14 13v5"/><path d="M14 13h2.5"/>',
    image: '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
    archive: '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
};
const ICON_COLORS = {
    folder: 'text-amber-500',
    file: 'text-slate-400',
    code: 'text-sky-600 dark:text-sky-400',
    text: 'text-slate-500 dark:text-slate-400',
    pdf: 'text-rose-500',
    image: 'text-emerald-600 dark:text-emerald-400',
    archive: 'text-violet-500',
};

const extOf = (name) => {
    const i = name.lastIndexOf('.');
    return i > 0 ? name.slice(i + 1).toLowerCase() : name.startsWith('.env') ? 'env' : '';
};

/** Merge objects keeping getters as getters (a plain spread would evaluate them). */
const mixin = (target, ...sources) => {
    for (const src of sources) Object.defineProperties(target, Object.getOwnPropertyDescriptors(src));
    return target;
};

export default (config) => mixin(fileManager(config), localPane());

const fileManager = (config) => ({
    server: config.server,
    chunkSize: config.chunkSize,

    path: '',
    items: [],
    loading: false,
    loadError: '',

    selected: [],
    anchor: null,
    history: [],
    future: [],

    sortKey: 'name',
    sortDir: 1,
    showHidden: true,

    filter: '',
    deep: false,
    searching: false,
    searchResults: null,
    searchTruncated: false,

    clipboard: null, // { mode: 'copy'|'cut', items: [{ path, name, dir }] }
    busy: '',

    menu: { open: false, x: 0, y: 0, item: null },
    dialog: { open: false, type: 'confirm', title: '', message: '', value: '', label: '', confirmText: '', cancelText: '', altText: '', danger: false },
    props: null,
    viewer: { open: false, kind: '', item: null, dirty: false, saving: false, mtime: null, size: 0, error: '', src: '', remoteChanged: null },
    transfersCollapsed: false,
    transfers: [], // uploads and downloads with progress: { id, kind: 'upload'|'download', mode, name, size, loaded, status, ... }
    toasts: [],
    dragOver: false,
    dragDepth: 0,
    dropTarget: null, // folder path when dragging over a folder row
    uploadBatch: { total: 0, ok: 0, failed: 0, paused: 0 },
    dark: currentTheme() === 'dark',

    // Archive viewer (contents of a .zip/.tar… without extracting it).
    archive: { open: false, item: null, loading: false, error: '', entries: [], format: '', size: 0, skipped: 0, truncated: false, filter: '' },

    // Folder watch: checks one folder periodically and alerts about new files.
    watch: { path: null, interval: 30, sound: true, lastCheck: null, error: '' },
    watchMenu: false,
    watchNew: [], // paths of files that arrived since the watch started (badge "جديد")
    watchGrowing: [], // paths whose size is still changing (probably still being uploaded)
    watchUnseen: 0, // shown in the tab title while the tab is in the background
    watchIntervals: WATCH_INTERVALS,

    // Quick switch between saved servers (list from this browser's localStorage, never passwords).
    serverMenu: false,
    savedServers: [],

    // Temporary transfer log of this connection (kept on the server, deleted on disconnect).
    logOpen: false,
    logEntries: [],
    logLoading: false,
    logFailures: 0,
    desktopNotify: loadNotifyPref(),

    // ------------------------------------------------------------------ lifecycle

    init() {
        const flash = takeFlash();
        if (flash) this.toast(flash, 'error');

        this.load(this.pathFromHash(), { replace: true });

        window.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('hashchange', () => {
            const p = this.pathFromHash();
            if (p !== this.path) this.navigate(p);
        });
        window.addEventListener('beforeunload', (e) => {
            if (this._leaving) return; // the user already confirmed (e.g. switching server)
            const busy = this.transfers.some((t) => ACTIVE.includes(t.status) && t.mode !== 'browser');
            if ((this.viewer.open && this.viewer.dirty) || busy) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        window.addEventListener('resize', () => this.closeMenu());
        window.addEventListener('focus', () => this.checkRemoteChange());
        // A drag that ends outside the window never fires dragleave on the page.
        window.addEventListener('dragend', () => {
            drag.payload = null;
            this.resetDrag();
            this.localDropTarget = null;
        });
        // Files dropped outside the drop zones must not make the browser navigate away to open them.
        window.addEventListener('dragover', (e) => dragKind(e) === 'files' && e.preventDefault());
        window.addEventListener('drop', (e) => {
            if (dragKind(e) === 'files') e.preventDefault();
            this.resetDrag();
        });

        this.initLocal();
        this.restorePendingUploads();
        this.initWatch();
    },

    // ------------------------------------------------------------------ computed

    get visibleItems() {
        let list = this.searchResults ?? this.items;
        if (!this.showHidden) list = list.filter((i) => !i.name.startsWith('.'));
        if (this.searchResults === null && this.filter.trim()) {
            const q = this.filter.trim().toLowerCase();
            list = list.filter((i) => i.name.toLowerCase().includes(q));
        }
        const key = this.sortKey;
        const dir = this.sortDir;
        return [...list].sort((a, b) => {
            if (a.dir !== b.dir) return a.dir ? -1 : 1;
            let x;
            let y;
            if (key === 'size') [x, y] = [a.size ?? -1, b.size ?? -1];
            else if (key === 'mtime') [x, y] = [a.mtime ?? 0, b.mtime ?? 0];
            else if (key === 'type') [x, y] = [this.typeLabel(a), this.typeLabel(b)];
            else if (key === 'owner') [x, y] = [this.ownerLabel(a), this.ownerLabel(b)];
            else return dir * a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
            return dir * (x < y ? -1 : x > y ? 1 : a.name.localeCompare(b.name));
        });
    },

    get selectedItems() {
        const set = new Set(this.selected);
        return this.visibleItems.filter((i) => set.has(i.path));
    },

    get single() {
        return this.selectedItems.length === 1 ? this.selectedItems[0] : null;
    },

    get breadcrumbs() {
        const crumbs = [{ label: this.server.root, path: '' }];
        let acc = '';
        for (const part of this.path.split('/').filter(Boolean)) {
            acc = acc ? `${acc}/${part}` : part;
            crumbs.push({ label: part, path: acc });
        }
        return crumbs;
    },

    // ------------------------------------------------------------------ helpers

    typeLabel(item) {
        if (item.dir) return item.link ? 'مجلد (رابط)' : 'مجلد';
        const ext = extOf(item.name);
        const label = TYPE_LABELS[ext] ?? (ext ? ext.toUpperCase() : 'ملف');
        return item.link ? `${label} (رابط)` : label;
    },

    iconKind(item) {
        if (item.dir) return 'folder';
        const ext = extOf(item.name);
        if (IMAGE_EXT.includes(ext)) return 'image';
        if (ext === 'pdf') return 'pdf';
        if (ARCHIVE_EXT.includes(ext)) return 'archive';
        if (CODE_EXT.includes(ext)) return 'code';
        if (TEXT_EXT.includes(ext)) return 'text';
        return 'file';
    },

    /** Owner name when the server lets us resolve it, otherwise the numeric UID. */
    ownerLabel(item) {
        if (item.uid === null || item.uid === undefined) return '';
        return item.owner ?? String(item.uid);
    },

    ownerTitle(item) {
        if (item.uid === null || item.uid === undefined) return '';
        const user = item.owner ? `${item.owner} (UID ${item.uid})` : `UID ${item.uid}`;
        const group = item.gid === null ? '' : item.group ? `${item.group} (GID ${item.gid})` : `GID ${item.gid}`;
        return `المالك: ${user}${group ? ` · المجموعة: ${group}` : ''}`;
    },

    rowIcon(item) {
        return ROW_ICONS[this.iconKind(item)];
    },

    iconColor(item) {
        return ICON_COLORS[this.iconKind(item)];
    },

    formatSize,
    formatDate,

    absPath(rel) {
        const root = this.server.root === '/' ? '' : this.server.root;
        return rel ? `${root}/${rel}` : this.server.root;
    },

    parentOf(rel) {
        const i = rel.lastIndexOf('/');
        return i === -1 ? '' : rel.slice(0, i);
    },

    pathFromHash() {
        const h = window.location.hash.replace(/^#\/?/, '');
        try {
            return h.split('/').filter(Boolean).map(decodeURIComponent).join('/');
        } catch {
            return '';
        }
    },

    toast(message, type = 'success', timeout = 3500) {
        const id = randomId();
        this.toasts.push({ id, message, type });
        setTimeout(() => (this.toasts = this.toasts.filter((t) => t.id !== id)), type === 'error' ? 6000 : timeout);
    },

    fail(e) {
        if (e?.name === 'AbortError') return;
        this.toast(e instanceof ApiError || e?.message ? e.message : 'حدث خطأ غير متوقع.', 'error');
    },

    report(res) {
        if (res.errors?.length) {
            const details = res.errors.slice(0, 3).map((e) => `• ${iso(e.path.split('/').pop())}: ${e.message}`).join('\n');
            this.toast(`${res.message}\n${details}`, 'error');
        } else {
            this.toast(res.message);
        }
        if (res.warning) this.toast(res.warning, 'warning');
    },

    // ------------------------------------------------------------------ navigation

    async load(path = this.path, { replace = false, keepSelection = false } = {}) {
        const seq = ++loadSeq;
        const cached = listCache.get(path);
        const changed = path !== this.path;
        this.loadError = '';
        this.loading = true;

        // Visited before: show it right away; the fresh listing replaces it when it arrives.
        if (cached && changed) this.showListing(path, cached, { replace, keepSelection: false });

        try {
            const res = await api('GET', '/api/list', { path });
            listCache.set(res.path, res.items);
            if (seq !== loadSeq) return true; // the user has moved on; keep the cache, don't touch the view
            this.showListing(res.path, res.items, { replace, keepSelection: keepSelection || !!cached });
            return true;
        } catch (e) {
            listCache.delete(path);
            if (seq !== loadSeq || e.status === 401) return false;
            if (!this.items.length && path !== '') {
                // Bad deep link: fall back to the root.
                this.toast(e.message, 'error');
                return this.load('', { replace: true });
            }
            this.loadError = e.message;
            this.fail(e);
            return false;
        } finally {
            if (seq === loadSeq) this.loading = false;
        }
    },

    showListing(path, items, { replace = false, keepSelection = false } = {}) {
        const changed = path !== this.path;
        this.path = path;
        this.items = items;
        if (changed || !keepSelection) {
            this.selected = keepSelection ? this.selected.filter((p) => items.some((i) => i.path === p)) : [];
            this.anchor = null;
        }
        if (changed) {
            this.filter = '';
            this.searchResults = null;
        }
        const hash = '#/' + path.split('/').filter(Boolean).map(encodeURIComponent).join('/');
        if (window.location.hash !== hash) window.history[replace ? 'replaceState' : 'pushState'](null, '', hash);
    },

    /**
     * POST a change and ask the server to include the current folder's fresh listing in the reply
     * (saves a second request — and a second SSH connection). Returns [response, listingApplied].
     */
    async mutate(url, data) {
        const payload = this.searchResults === null ? { ...data, list: this.path } : data;
        try {
            const res = await api('POST', url, payload);
            return [res, this.applyListing(res.listing)];
        } catch (e) {
            e.listingApplied = this.applyListing(e.body?.listing);
            throw e;
        }
    },

    applyListing(listing) {
        if (!listing) return false;
        listCache.clear(); // other folders may have changed too (moves, copies)
        listCache.set(listing.path, listing.items);
        if (listing.path !== this.path || this.searchResults !== null) return false;
        this.items = listing.items;
        this.selected = this.selected.filter((p) => listing.items.some((i) => i.path === p));
        return true;
    },

    /** Many uploads finishing close together → one refresh, not one per file. */
    scheduleRefresh(dir) {
        listCache.delete(dir);
        if (dir !== this.path || this.searchResults !== null) return;
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => this.load(this.path, { keepSelection: true }), 700);
    },

    async navigate(path) {
        if (path === this.path && this.searchResults === null) return this.refresh();
        const from = this.path;
        if (await this.load(path)) {
            if (from !== this.path) {
                this.history.push(from);
                this.future = [];
            }
        }
    },

    async goBack() {
        if (!this.history.length) return this.goUp();
        const prev = this.history.pop();
        const from = this.path;
        if (await this.load(prev)) this.future.push(from);
    },

    async goForward() {
        if (!this.future.length) return;
        const next = this.future.pop();
        const from = this.path;
        if (await this.load(next)) this.history.push(from);
    },

    goUp() {
        if (this.path !== '') this.navigate(this.parentOf(this.path));
    },

    refresh(keepSelection = true) {
        if (this.searchResults !== null) return this.runSearch();
        return this.load(this.path, { keepSelection });
    },

    sortBy(key) {
        if (this.sortKey === key) this.sortDir *= -1;
        else {
            this.sortKey = key;
            this.sortDir = 1;
        }
    },

    // ------------------------------------------------------------------ selection

    isSelected(item) {
        return this.selected.includes(item.path);
    },

    click(e, item) {
        if (this.watchNew.includes(item.path)) this.watchNew = this.watchNew.filter((p) => p !== item.path);
        this.closeMenu();
        const list = this.visibleItems;
        if (e.shiftKey && this.anchor !== null) {
            const a = list.findIndex((i) => i.path === this.anchor);
            const b = list.findIndex((i) => i.path === item.path);
            if (a !== -1 && b !== -1) {
                const [from, to] = a < b ? [a, b] : [b, a];
                this.selected = list.slice(from, to + 1).map((i) => i.path);
                return;
            }
        }
        if (e.ctrlKey || e.metaKey) {
            this.selected = this.isSelected(item) ? this.selected.filter((p) => p !== item.path) : [...this.selected, item.path];
        } else {
            this.selected = [item.path];
        }
        this.anchor = item.path;
    },

    selectAll() {
        this.selected = this.visibleItems.map((i) => i.path);
    },

    moveSelection(delta) {
        const list = this.visibleItems;
        if (!list.length) return;
        const current = list.findIndex((i) => i.path === (this.anchor ?? this.selected.at(-1)));
        const next = Math.max(0, Math.min(list.length - 1, current === -1 ? 0 : current + delta));
        this.selected = [list[next].path];
        this.anchor = list[next].path;
        this.$nextTick(() => document.querySelector(`[data-path="${CSS.escape(list[next].path)}"]`)?.scrollIntoView({ block: 'nearest' }));
    },

    // ------------------------------------------------------------------ context menu

    openMenu(e, item = null) {
        e.preventDefault();
        if (item && !this.isSelected(item)) {
            this.selected = [item.path];
            this.anchor = item.path;
        }
        if (!item) this.selected = [];
        // Render off-screen first, then place it once its size is known (RTL: open to the left of the cursor).
        this.menu = { open: true, x: -9999, y: -9999, item };
        requestAnimationFrame(() => {
            const el = this.$refs.menu;
            if (!el) return;
            const w = el.offsetWidth;
            const h = el.offsetHeight;
            let x = e.clientX - w;
            if (x < 8) x = e.clientX;
            this.menu.x = Math.max(8, Math.min(x, window.innerWidth - w - 8));
            this.menu.y = Math.max(8, Math.min(e.clientY, window.innerHeight - h - 8));
        });
    },

    closeMenu() {
        this.menu.open = false;
    },

    menuAction(fn) {
        this.closeMenu();
        fn();
    },

    // ------------------------------------------------------------------ dialogs

    ask({ title, label = '', value = '', confirmText = 'موافق', selectStem = false }) {
        this.dialog = { open: true, type: 'prompt', title, label, value, message: '', confirmText, cancelText: 'إلغاء', altText: '', danger: false };
        this.$nextTick(() => {
            const input = this.$refs.dialogInput;
            if (!input) return;
            input.focus();
            const dot = value.lastIndexOf('.');
            input.setSelectionRange(0, selectStem && dot > 0 ? dot : value.length);
        });
        return new Promise((resolve) => (dialogResolve = resolve));
    },

    /** Resolves true / false, or 'alt' when the optional third button is chosen. */
    confirm({ title, message, confirmText = 'تأكيد', cancelText = 'إلغاء', altText = '', danger = false, focusCancel = false }) {
        this.dialog = { open: true, type: 'confirm', title, message, label: '', value: '', confirmText, cancelText, altText, danger };
        this.$nextTick(() => (focusCancel ? this.$refs.dialogCancel : this.$refs.dialogConfirm)?.focus());
        return new Promise((resolve) => (dialogResolve = resolve));
    },

    closeDialog(ok) {
        const value = this.dialog.type === 'prompt' ? (ok ? this.dialog.value.trim() : null) : ok;
        this.dialog.open = false;
        document.activeElement?.blur(); // don't leave focus in the hidden input
        dialogResolve?.(value);
        dialogResolve = null;
    },

    // ------------------------------------------------------------------ open / view / edit

    open(item) {
        if (!item) return;
        if (item.dir) {
            this.searchResults = null;
            return this.navigate(item.path);
        }
        return this.openFile(item);
    },

    async openFile(item) {
        const ext = extOf(item.name);
        const src = `/api/download?inline=1&path=${encodeURIComponent(item.path)}`;
        this.viewer = { open: true, kind: 'loading', item, dirty: false, saving: false, mtime: item.mtime, size: item.size, error: '', src: '', remoteChanged: null };

        if (IMAGE_EXT.includes(ext)) return Object.assign(this.viewer, { kind: 'image', src });
        if (this.isArchive(item) || ['rar', '7z'].includes(ext)) {
            this.viewer.open = false;
            return this.openArchive(item);
        }
        if (ext === 'pdf') return Object.assign(this.viewer, { kind: 'pdf', src });

        try {
            const [res, { createEditor }] = await Promise.all([api('GET', '/api/read', { path: item.path }), import('./editor')]);
            if (!this.viewer.open || this.viewer.item !== item) return;
            Object.assign(this.viewer, { mtime: res.mtime, size: res.size });
            if (res.tooLarge) return (this.viewer.kind = 'tooLarge');
            if (res.binary) return (this.viewer.kind = 'binary');

            this.viewer.kind = 'editor';
            await this.$nextTick();
            editor?.destroy();
            editor = createEditor(this.$refs.editor, {
                content: res.content,
                filename: item.name,
                dark: this.dark,
                onChange: () => (this.viewer.dirty = true),
                onSave: () => this.save(),
            });
            editor.focus();
        } catch (e) {
            if (e.status === 401) return;
            this.viewer.kind = 'error';
            this.viewer.error = e.message;
        }
    },

    async save(force = false) {
        if (this.viewer.kind !== 'editor' || !editor || this.viewer.saving) return;
        this.viewer.saving = true;
        const content = editor.getValue();
        try {
            const res = await api('POST', '/api/write', { path: this.viewer.item.path, content, mtime: this.viewer.mtime, size: this.viewer.size, force });
            Object.assign(this.viewer, { mtime: res.mtime, size: res.size, remoteChanged: null });
            if (editor.getValue() === content) this.viewer.dirty = false;
            this.toast(res.message);
            this.scheduleRefresh(this.parentOf(this.viewer.item.path));
            if (this.logOpen) this.loadLog();
        } catch (e) {
            if (e.status === 409 && !force) {
                this.viewer.saving = false;
                const server = e.body?.server;
                const details = server
                    ? `\n\nنسخة السيرفر الآن: آخر تعديل ${iso(formatDate(server.mtime))} · الحجم ${iso(formatSize(server.size))}\nالنسخة التي فتحتها: آخر تعديل ${iso(formatDate(this.viewer.mtime))} · الحجم ${iso(formatSize(this.viewer.size))}`
                    : '';
                const choice = await this.confirm({
                    title: 'تحذير: الملف تغيّر على السيرفر',
                    message: `${e.message}${details}\n\nإذا حفظت الآن ستُستبدل التغييرات الموجودة على السيرفر بنسختك.`,
                    confirmText: 'استبدال بنسختي',
                    altText: server ? 'تحميل نسخة السيرفر' : '',
                    cancelText: 'إلغاء',
                    danger: true,
                    focusCancel: true, // Enter must not overwrite someone else's changes
                });
                if (choice === true) return this.save(true);
                if (choice === 'alt') return this.reloadFromServer(true);
                return;
            }
            this.fail(e);
        } finally {
            this.viewer.saving = false;
        }
    },

    /** Replace the editor content with the current server version (discarding local edits). */
    async reloadFromServer(confirmed = false) {
        if (!confirmed && this.viewer.dirty) {
            const ok = await this.confirm({
                title: 'تحميل نسخة السيرفر',
                message: 'سيتم تجاهل تغييراتك غير المحفوظة وتحميل النسخة الموجودة على السيرفر. متابعة؟',
                confirmText: 'تحميل وتجاهل تغييراتي',
                danger: true,
            });
            if (!ok) return;
        }
        this.viewer.dirty = false;
        editor?.destroy();
        editor = null;
        await this.openFile(this.viewer.item);
        this.toast('تم تحميل أحدث نسخة من السيرفر.', 'info');
    },

    /** Called when the window regains focus: warn early if the open file changed on the server. */
    async checkRemoteChange() {
        const v = this.viewer;
        if (!v.open || v.kind !== 'editor' || v.saving || Date.now() - (this._lastRemoteCheck ?? 0) < 5000) return;
        this._lastRemoteCheck = Date.now();
        try {
            const info = await api('GET', '/api/stat', { path: v.item.path });
            if (this.viewer.item !== v.item) return;
            v.remoteChanged = info.mtime !== v.mtime || info.size !== v.size ? { mtime: info.mtime, size: info.size } : null;
        } catch (e) {
            if (e.status === 404) v.remoteChanged = { deleted: true };
        }
    },

    async closeViewer() {
        if (this.viewer.dirty) {
            const ok = await this.confirm({
                title: 'تغييرات غير محفوظة',
                message: `لم يتم حفظ التغييرات على "${iso(this.viewer.item.name)}". هل تريد الإغلاق دون حفظ؟`,
                confirmText: 'إغلاق دون حفظ',
                danger: true,
            });
            if (!ok) return;
        }
        editor?.destroy();
        editor = null;
        this.viewer = { open: false, kind: '', item: null, dirty: false, saving: false, mtime: null, size: 0, error: '', src: '', remoteChanged: null };
    },

    // ------------------------------------------------------------------ file operations

    download(items = this.selectedItems) {
        if (!items.length) return;
        // Folders can only be downloaded as one ZIP file.
        if (items.some((i) => i.dir)) return this.downloadAsZip(items);
        const files = items;
        files.forEach((item, n) =>
            setTimeout(() => {
                const tid = hexId();
                // Saved by the browser into its downloads folder; progress = bytes the server has sent.
                this.transfers.push({
                    id: `dl-${tid}`, tid, kind: 'download', mode: 'browser', name: item.name, size: item.size ?? 0, loaded: 0,
                    status: 'waiting', error: '', path: item.path, from: this.absPath(item.path), to: 'مجلد التنزيلات في المتصفح', since: Date.now(), startedAt: null,
                });
                this.watchLog();
                const a = document.createElement('a');
                a.href = `/api/download?path=${encodeURIComponent(item.path)}&tid=${tid}`;
                a.download = item.name;
                document.body.appendChild(a);
                a.click();
                a.remove();
            }, n * 400),
        );
    },

    async newFolder() {
        const name = await this.ask({ title: 'مجلد جديد', label: 'اسم المجلد', value: 'مجلد جديد', confirmText: 'إنشاء' });
        if (!name) return;
        await this.createEntry('/api/mkdir', name);
    },

    async newFile() {
        const name = await this.ask({ title: 'ملف جديد', label: 'اسم الملف', value: 'new-file.txt', confirmText: 'إنشاء', selectStem: true });
        if (!name) return;
        await this.createEntry('/api/touch', name);
    },

    async createEntry(url, name) {
        try {
            const [res, applied] = await this.mutate(url, { path: this.path, name });
            this.toast(res.message);
            if (!applied) await this.load(this.path);
            const p = this.path ? `${this.path}/${name}` : name;
            this.selected = [p];
            this.anchor = p;
        } catch (e) {
            this.fail(e);
        }
    },

    async rename(item = this.single) {
        if (!item) return;
        const name = await this.ask({ title: 'إعادة تسمية', label: 'الاسم الجديد', value: item.name, confirmText: 'حفظ', selectStem: !item.dir });
        if (!name || name === item.name) return;
        try {
            const [res, applied] = await this.mutate('/api/rename', { path: item.path, name });
            this.toast(res.message);
            const newPath = this.parentOf(item.path) ? `${this.parentOf(item.path)}/${name}` : name;
            if (!applied) await this.refresh(false);
            this.selected = [newPath];
            this.anchor = newPath;
        } catch (e) {
            this.fail(e);
        }
    },

    async remove(items = this.selectedItems) {
        if (!items.length) return;
        let message;
        if (items.length === 1) {
            const item = items[0];
            message = `هل أنت متأكد من حذف:\n${iso(item.name)}؟`;
            if (item.dir && !item.link) {
                try {
                    const info = await api('GET', '/api/stat', { path: item.path });
                    if (info.children > 0) {
                        message = `المجلد "${iso(item.name)}" يحتوي على ${info.children} عنصر.\nسيتم حذف المجلد وجميع محتوياته نهائيًا ولا يمكن التراجع.`;
                    }
                } catch (e) {
                    return this.fail(e);
                }
            }
        } else {
            const folders = items.filter((i) => i.dir && !i.link).length;
            const names = items.slice(0, 6).map((i) => `• ${iso(i.name)}`).join('\n');
            message = `هل أنت متأكد من حذف ${items.length} عنصر؟\n${names}${items.length > 6 ? '\n…' : ''}`;
            if (folders) message += `\n\nتنبيه: يشمل ذلك ${folders} مجلد وسيتم حذف جميع محتوياتها.`;
        }

        const ok = await this.confirm({ title: 'تأكيد الحذف', message, confirmText: 'حذف', danger: true });
        if (!ok) return;

        this.busy = 'جارٍ الحذف…';
        let applied = false;
        try {
            const [res, ok] = await this.mutate('/api/delete', { paths: items.map((i) => i.path) });
            applied = ok;
            this.report(res);
        } catch (e) {
            applied = !!e.listingApplied;
            this.fail(e.body?.errors?.length ? { message: e.body.errors[0].message } : e);
        } finally {
            this.busy = '';
            if (!applied) this.refresh(false);
        }
    },

    copy(mode = 'copy', items = this.selectedItems) {
        if (!items.length) return;
        this.clipboard = { mode, items: items.map(({ path, name, dir }) => ({ path, name, dir })) };
        this.toast(`${mode === 'cut' ? 'قص' : 'نسخ'} ${items.length === 1 ? `"${iso(items[0].name)}"` : `${items.length} عنصر`} — انتقل إلى المجلد الهدف ثم الصق (Ctrl+V)`, 'info');
    },

    isCut(item) {
        return this.clipboard?.mode === 'cut' && this.clipboard.items.some((i) => i.path === item.path);
    },

    async paste() {
        if (!this.clipboard) return;
        const { mode, items } = this.clipboard;
        this.busy = mode === 'cut' ? 'جارٍ النقل…' : 'جارٍ النسخ…';
        this.searchResults = null;
        let applied = false;
        try {
            const [res, ok] = await this.mutate('/api/paste', { mode, paths: items.map((i) => i.path), dest: this.path });
            applied = ok;
            this.report(res);
            if (mode === 'cut') this.clipboard = null;
        } catch (e) {
            applied = !!e.listingApplied;
            this.fail(e.body?.errors?.length ? { message: e.body.errors[0].message } : e);
        } finally {
            this.busy = '';
            if (!applied) this.load(this.path);
        }
    },

    async properties(item = this.single) {
        if (!item) return;
        try {
            this.props = await api('GET', '/api/stat', { path: item.path });
        } catch (e) {
            this.fail(e);
        }
    },

    // ------------------------------------------------------------------ search

    onFilterInput() {
        if (!this.filter.trim()) this.searchResults = null;
    },

    async runSearch() {
        const q = this.filter.trim();
        if (!this.deep || !q) return;
        this.searching = true;
        try {
            const res = await api('GET', '/api/search', { path: this.path, q });
            this.searchResults = res.items;
            this.searchTruncated = res.truncated;
            this.selected = [];
        } catch (e) {
            this.fail(e);
        } finally {
            this.searching = false;
        }
    },

    clearSearch() {
        this.filter = '';
        this.searchResults = null;
    },

    // ------------------------------------------------------------------ upload

    pickFiles() {
        this.$refs.fileInput.value = '';
        this.$refs.fileInput.click();
    },

    resetDrag() {
        this.dragDepth = 0;
        this.dragOver = false;
        this.dropTarget = null;
    },

    /** Server rows can be dragged onto a server folder (move) or onto the local panel (download). */
    onRemoteDragStart(e, item) {
        if (!this.isSelected(item)) {
            this.selected = [item.path];
            this.anchor = item.path;
        }
        drag.payload = { source: 'remote', items: this.selectedItems };
        e.dataTransfer.setData(REMOTE_TYPE, '1');
        e.dataTransfer.effectAllowed = 'copyMove';
    },

    onDragEnter(e) {
        const kind = dragKind(e);
        if (!kind) return;
        this.dragDepth++;
        this.dragOver = kind !== 'remote'; // the upload frame is only for things coming from the device
    },

    onDragLeave(e) {
        if (!dragKind(e)) return;
        this.dragDepth = Math.max(0, this.dragDepth - 1);
        if (!this.dragDepth) this.resetDrag();
    },

    onDragOver(e, item = null) {
        const kind = dragKind(e);
        if (!kind) return;
        if (kind === 'remote') {
            const moving = drag.payload?.items ?? [];
            const valid = item?.dir && !moving.some((m) => m.path === item.path || item.path.startsWith(`${m.path}/`));
            e.dataTransfer.dropEffect = valid ? 'move' : 'none';
            this.dropTarget = valid ? item.path : null;
            return;
        }
        e.dataTransfer.dropEffect = 'copy';
        this.dropTarget = item?.dir ? item.path : null;
    },

    /** Drop files and/or folders. Dropping on a folder row uploads into that folder. */
    async onDrop(e, item = null) {
        const kind = dragKind(e);
        const payload = drag.payload;
        const targetDir = item?.dir ? item.path : this.path;
        if (kind === 'remote') {
            const valid = this.dropTarget;
            this.resetDrag();
            return valid && payload ? this.moveTo(payload.items, valid) : undefined;
        }
        if (kind === 'local') {
            this.resetDrag();
            return payload ? this.uploadLocal(payload.items, targetDir) : undefined;
        }
        if (kind !== 'files') return this.resetDrag();
        // DataTransfer is emptied after the event: grab the entries synchronously.
        const entries = [...(e.dataTransfer.items ?? [])].filter((i) => i.kind === 'file').map((i) => i.webkitGetAsEntry?.()).filter(Boolean);
        const plainFiles = [...e.dataTransfer.files];
        this.resetDrag();

        try {
            const { files, dirs } = entries.length ? await collectEntries(entries) : { files: plainFiles.map((f) => ({ file: f, rel: f.name })), dirs: [] };
            await this.startUploads(files, dirs, targetDir);
        } catch (err) {
            this.fail(err);
        }
    },

    async moveTo(items, destDir) {
        const ok = await this.confirm({
            title: 'نقل على السيرفر',
            message: `نقل ${items.length === 1 ? iso(items[0].name) : `${items.length} عنصر`} إلى المجلد ${iso(this.absPath(destDir))}؟`,
            confirmText: 'نقل',
        });
        if (!ok) return;
        this.busy = 'جارٍ النقل…';
        let applied = false;
        try {
            const [res, ok] = await this.mutate('/api/paste', { mode: 'cut', paths: items.map((i) => i.path), dest: destDir });
            applied = ok;
            this.report(res);
        } catch (e) {
            applied = !!e.listingApplied;
            this.fail(e.body?.errors?.length ? { message: e.body.errors[0].message } : e);
        } finally {
            this.busy = '';
            if (!applied) this.refresh(false);
        }
    },

    addUploads(fileList) {
        return this.startUploads([...fileList].map((f) => ({ file: f, rel: f.name })), [], this.path);
    },

    /**
     * @param {{file: File, rel: string}[]} files  rel = path relative to the drop (e.g. "photos/2024/a.jpg")
     * @param {string[]} dirs  folders to create (relative, parents first)
     */
    async startUploads(files, dirs, targetDir, { overwrite: forcedOverwrite } = {}) {
        if (!files.length && !dirs.length) return;

        // Ask once about top-level names that already exist in the target folder
        // (skipped when the caller already decided, e.g. the sync window).
        let existing;
        if (forcedOverwrite !== undefined) {
            existing = new Set();
        } else if (targetDir === this.path && this.searchResults === null) {
            existing = new Set(this.items.map((i) => i.name));
        } else {
            try {
                existing = new Set((await api('GET', '/api/list', { path: targetDir })).items.map((i) => i.name));
            } catch (e) {
                if (e.status !== 403) return this.fail(e);
                // Like FileZilla: a folder we may write into but not list (e.g. -wx) still accepts uploads.
                // Existing files are then protected by the server (it refuses to overwrite them).
                existing = new Set();
                this.toast(`لا يمكن عرض محتوى المجلد ${iso(this.absPath(targetDir))}؛ سيتم الرفع إليه مباشرة.`, 'info');
            }
        }
        const topNames = [...new Set([...files.map((f) => f.rel.split('/')[0]), ...dirs.map((d) => d.split('/')[0])])];
        const conflicts = topNames.filter((n) => existing.has(n));
        let overwrite = forcedOverwrite ?? false;
        if (conflicts.length) {
            overwrite = await this.confirm({
                title: 'عناصر موجودة',
                message: `العناصر التالية موجودة مسبقًا في المجلد الهدف:\n${conflicts.slice(0, 8).map((n) => `• ${iso(n)}`).join('\n')}${conflicts.length > 8 ? '\n…' : ''}\n\nاستبدال الملفات المتطابقة (والدمج داخل المجلدات)؟ "تخطي" يرفع العناصر الأخرى فقط.`,
                confirmText: 'استبدال',
                cancelText: 'تخطي',
                danger: true,
            });
        }
        const skipped = (rel) => !overwrite && conflicts.includes(rel.split('/')[0]);

        // Create folders first (parents before children). Existing folders are fine.
        const failedDirs = [];
        for (const dir of [...dirs].sort((a, b) => a.split('/').length - b.split('/').length)) {
            if (skipped(dir) || failedDirs.some((f) => dir.startsWith(`${f}/`))) continue;
            try {
                await api('POST', '/api/mkdir', { path: joinRel(targetDir, parentRel(dir)), name: dir.split('/').pop() });
            } catch (e) {
                if (e.status === 401) return;
                if (e.status !== 409) {
                    failedDirs.push(dir);
                    this.notify(`تعذر إنشاء المجلد ${iso(dir)}: ${e.message}`, 'error', 'فشل الرفع');
                }
            }
        }
        if (dirs.length) this.scheduleRefresh(targetDir);

        let queued = 0;
        for (const { file, rel, source } of files) {
            if (skipped(rel) || failedDirs.some((f) => rel.startsWith(`${f}/`))) continue;
            const id = randomId();
            const dir = joinRel(targetDir, parentRel(rel));
            // Same file (name + size + date) that was interrupted earlier? Continue it instead of starting over.
            const pending = pendingAll()[pendingKey(this.server, dir, file.name)];
            const resumable = pending && pending.size === file.size && pending.lastModified === file.lastModified;
            if (resumable) this.transfers = this.transfers.filter((t) => !(t.orphan && t.uploadId === pending.uploadId));
            uploadFiles.set(id, file);
            this.transfers.push({
                id, kind: 'upload', mode: 'upload', name: file.name, source: source ?? rel, size: file.size, loaded: 0, status: 'queued', error: '',
                dir, from: source ?? rel, to: this.absPath(joinRel(dir, file.name)), overwrite: resumable ? pending.overwrite : overwrite, startedAt: null,
                uploadId: resumable ? pending.uploadId : null, resume: !!resumable, attempt: 0, retryIn: 0,
            });
            if (resumable) this.toast(`سيتم استئناف رفع ${iso(file.name)} من حيث توقف.`, 'info');
            queued++;
        }
        this.uploadBatch.total += queued;
        if (queued > 1 || targetDir !== this.path) {
            this.toast(`جارٍ رفع ${queued} ملف إلى ${iso(this.absPath(targetDir))}…`, 'info');
        }
        this.processUploads();
    },

    async processUploads() {
        if (this._uploading) return;
        this._uploading = true;
        try {
            let next;
            while ((next = this.transfers.find((t) => t.kind === 'upload' && t.status === 'queued'))) {
                await this.uploadOne(next);
            }
        } finally {
            this._uploading = false;
            // One summary notification for multi-file batches instead of one per file.
            const b = this.uploadBatch;
            if (b.total > 3) {
                const extra = [b.failed && `فشل ${b.failed}`, b.paused && `توقف ${b.paused} مؤقتًا`].filter(Boolean).join('، ');
                const msg = extra ? `اكتمل الرفع: نجح ${b.ok} من ${b.total} ملف (${extra}). التفاصيل في لوحة النقل وسجل النقل.` : `تم رفع ${b.ok} ملف إلى السيرفر بنجاح.`;
                this.notify(msg, b.failed ? 'error' : b.paused ? 'warning' : 'success', 'اكتمل الرفع');
            }
            this.uploadBatch = { total: 0, ok: 0, failed: 0, paused: 0 };
        }
    },

    async uploadOne(u) {
        const file = uploadFiles.get(u.id);
        const total = file.size;
        const key = pendingKey(this.server, u.dir, u.name);
        u.uploadId ||= randomId();
        Object.assign(u, { status: 'uploading', error: '', attempt: 0, retryIn: 0, cancelRequested: false });

        // Multi-chunk files are worth resuming: remember them until they finish.
        if (total > this.chunkSize) {
            const all = pendingAll();
            all[key] = { uploadId: u.uploadId, dir: u.dir, name: u.name, size: total, lastModified: file.lastModified, overwrite: u.overwrite, source: u.source, saved: Date.now() };
            pendingWrite(all);
        }
        const forget = () => {
            const all = pendingAll();
            delete all[key];
            pendingWrite(all);
        };
        const logResult = (reason, message = '') =>
            api('POST', '/api/upload/abort', { path: u.dir, name: u.name, uploadId: u.uploadId, total, source: u.source ?? u.name, reason, message: message.slice(0, 500) })
                .catch(() => {})
                .finally(() => this.logOpen && this.loadLog());

        let offset = 0;
        try {
            for (;;) {
                try {
                    // Resuming (or retrying): ask the server how much it already has.
                    if (u.resume || u.attempt > 0) {
                        const { size } = await api('GET', '/api/upload/status', { path: u.dir, name: u.name, uploadId: u.uploadId });
                        offset = Math.min(size ?? 0, total);
                    }
                    u.status = 'uploading';
                    u.error = '';
                    u.loaded = offset;
                    u.baseLoaded = offset; // for the speed calculation
                    u.startedAt = Date.now();

                    do {
                        const end = Math.min(offset + this.chunkSize, total);
                        const fd = new FormData();
                        fd.append('path', u.dir);
                        fd.append('name', u.name);
                        fd.append('uploadId', u.uploadId);
                        fd.append('offset', offset);
                        fd.append('total', total);
                        fd.append('overwrite', u.overwrite ? '1' : '0');
                        fd.append('source', u.source ?? u.name);
                        if (total > 0) fd.append('chunk', file.slice(offset, end), 'chunk');

                        const start = offset;
                        await uploadWithProgress('/api/upload', fd, (loaded) => (u.loaded = Math.min(end, start + loaded)), (xhr) => uploadXhrs.set(u.id, xhr));
                        offset = end;
                        u.loaded = offset;
                    } while (offset < total);
                    break;
                } catch (e) {
                    if (e.name === 'AbortError' || !RETRYABLE.includes(e.status ?? 0) || u.attempt >= RETRY_DELAYS.length) throw e;
                    u.error = e.status === 503 ? 'انقطع الاتصال بسيرفر SFTP' : 'انقطع الاتصال بالشبكة';
                    await this.waitBeforeRetry(u, RETRY_DELAYS[u.attempt++]);
                }
            }

            u.status = 'done';
            u.error = '';
            forget();
            this.uploadBatch.ok++;
            if (this.uploadBatch.total <= 3) {
                this.notify(`تم رفع ${iso(u.name)} إلى ${iso(this.absPath(u.dir))} بنجاح (${iso(formatSize(total))}).`, 'success', 'اكتمل الرفع');
            }
            this.scheduleRefresh(u.dir);
        } catch (e) {
            if (e.status === 401) return; // session expired: the page is redirecting
            const canceled = e.name === 'AbortError';
            const paused = !canceled && RETRYABLE.includes(e.status ?? 0);

            if (paused) {
                // Keep the partial file on the server and the File in memory: "استئناف" continues from there.
                Object.assign(u, { status: 'paused', resume: true, error: 'توقف بعد عدة محاولات بسبب انقطاع الاتصال. يمكنك استئنافه.' });
                this.uploadBatch.paused++;
                this.notify(`توقف رفع ${iso(u.name)} مؤقتًا بسبب انقطاع الاتصال. اضغط "استئناف" لإكماله من حيث توقف.`, 'warning', 'رفع متوقف');
                logResult('paused');
                return;
            }

            u.status = canceled ? 'canceled' : 'error';
            u.error = canceled ? '' : e.message;
            forget();
            if (!canceled) this.uploadBatch.failed++;
            if (canceled) this.toast(`تم إلغاء رفع ${iso(u.name)}.`, 'warning');
            else if (this.uploadBatch.total <= 3) this.notify(`فشل رفع ${iso(u.name)}: ${e.message}`, 'error', 'فشل الرفع');
            else this.logFailures++;
            // Deletes the partial file and records the result in the transfer log.
            logResult(canceled ? 'canceled' : 'failed', e.message ?? '');
        } finally {
            if (this.logOpen) this.loadLog();
            uploadXhrs.delete(u.id);
            if (u.status !== 'paused') uploadFiles.delete(u.id);
        }
    },

    /** Countdown before the next attempt; while the browser is offline, wait for the network first. */
    async waitBeforeRetry(u, seconds) {
        u.status = 'retrying';
        for (let s = seconds; s > 0; s--) {
            if (u.cancelRequested) throw new DOMException('Aborted', 'AbortError');
            u.retryIn = s;
            await sleep(1000);
        }
        while (!navigator.onLine) {
            if (u.cancelRequested) throw new DOMException('Aborted', 'AbortError');
            u.retryIn = 0;
            u.error = 'لا يوجد اتصال بالإنترنت — بانتظار عودة الشبكة…';
            await sleep(1000);
        }
        u.retryIn = 0;
    },

    /** Continue a paused upload from the bytes already on the server. */
    resumeTransfer(t) {
        if (!uploadFiles.has(t.id)) return this.pickResumeFile(t);
        Object.assign(t, { status: 'queued', resume: true, error: '' });
        this.uploadBatch.total++;
        this.processUploads();
    },

    /** After a page reload the browser no longer has the file: ask for it again (must be the same file). */
    pickResumeFile(t) {
        this._resumeTarget = t;
        this.$refs.resumeInput.value = '';
        this.$refs.resumeInput.click();
    },

    onResumeFile(file) {
        const t = this._resumeTarget;
        this._resumeTarget = null;
        if (!t || !file) return;
        if (file.name !== t.name || file.size !== t.size || (t.lastModified && file.lastModified !== t.lastModified)) {
            return this.toast(`الملف المختار لا يطابق ${iso(t.name)} (الاسم والحجم وتاريخ التعديل). اختر نفس الملف.`, 'error');
        }
        uploadFiles.set(t.id, file);
        t.orphan = false;
        this.resumeTransfer(t);
    },

    /** Show uploads interrupted in a previous visit (this browser, this server) as paused items. */
    async restorePendingUploads() {
        const prefix = `${serverId(this.server)}|`;
        const records = Object.entries(pendingAll()).filter(([k]) => k.startsWith(prefix));
        for (const [key, r] of records) {
            try {
                const { size } = await api('GET', '/api/upload/status', { path: r.dir, name: r.name, uploadId: r.uploadId });
                if (size === null) {
                    // Nothing left on the server (finished, deleted or cleaned up): forget it.
                    const all = pendingAll();
                    delete all[key];
                    pendingWrite(all);
                    continue;
                }
                this.transfers.push({
                    id: randomId(), kind: 'upload', mode: 'upload', name: r.name, source: r.source, size: r.size, loaded: size, status: 'paused', orphan: true,
                    error: 'رفع غير مكتمل من زيارة سابقة. اختر نفس الملف للاستئناف.', dir: r.dir, from: r.source ?? r.name, to: this.absPath(joinRel(r.dir, r.name)),
                    overwrite: r.overwrite, uploadId: r.uploadId, lastModified: r.lastModified, resume: true, attempt: 0, retryIn: 0, startedAt: null,
                });
            } catch (e) {
                if (e.status === 401) return;
            }
        }
    },

    cancelTransfer(t) {
        if (t.kind === 'download') return this.cancelLocalDownload(t);
        if (t.status === 'queued') {
            t.status = 'canceled';
            uploadFiles.delete(t.id);
        } else if (t.status === 'uploading') {
            uploadXhrs.get(t.id)?.abort();
        } else if (t.status === 'retrying') {
            t.cancelRequested = true;
        } else if (t.status === 'paused') {
            // Give up on it: delete the partial file on the server and forget it.
            t.status = 'canceled';
            uploadFiles.delete(t.id);
            const all = pendingAll();
            delete all[pendingKey(this.server, t.dir, t.name)];
            pendingWrite(all);
            api('POST', '/api/upload/abort', { path: t.dir, name: t.name, uploadId: t.uploadId, total: t.size, source: t.source ?? t.name, reason: 'canceled' }).catch(() => {});
        }
    },

    clearTransfers() {
        this.transfers = this.transfers.filter((t) => ACTIVE.includes(t.status));
    },

    get activeTransfers() {
        return this.transfers.filter((t) => ACTIVE.includes(t.status)).length;
    },

    get activeUploads() {
        return this.transfers.filter((t) => t.kind === 'upload' && ACTIVE.includes(t.status)).length;
    },

    percent(t) {
        if (t.status === 'done') return 100;
        return t.size ? Math.min(100, Math.floor((t.loaded / t.size) * 100)) : 0;
    },

    /** "3.2 MB/s · 00:12 left" */
    transferRate(t) {
        if (!['uploading', 'downloading'].includes(t.status) || !t.startedAt || !t.loaded) return '';
        const secs = (Date.now() - t.startedAt) / 1000;
        if (secs < 0.5 || t.loaded <= (t.baseLoaded ?? 0)) return '';
        const rate = (t.loaded - (t.baseLoaded ?? 0)) / secs;
        const left = t.size > t.loaded ? Math.round((t.size - t.loaded) / rate) : 0;
        const eta = left >= 3600 ? `${Math.floor(left / 3600)}h ${Math.floor((left % 3600) / 60)}m` : `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
        return `${formatSize(Math.round(rate))}/s · ${eta}`;
    },

    transferStatus(t) {
        if (t.phase && ['working', 'downloading'].includes(t.status)) {
            const pct = t.size ? ` ${this.percent(t)}%` : '';
            return `${PHASES[t.phase] ?? ''}${pct}`;
        }
        return {
            queued: 'في الانتظار', waiting: 'بانتظار المتصفح…', uploading: `${this.percent(t)}%`, downloading: `${this.percent(t)}%`,
            done: 'اكتمل', error: 'فشل', canceled: 'ملغى', unknown: 'غير معروف', paused: 'متوقف مؤقتًا', working: 'جارٍ التحضير…',
            retrying: t.retryIn ? `إعادة خلال ${t.retryIn}ث` : 'إعادة المحاولة…',
        }[t.status];
    },

    // ------------------------------------------------------------------ archives

    isArchive(item) {
        return !!item && !item.dir && OPENABLE_ARCHIVE.test(item.name);
    },

    async openArchive(item) {
        this.archive = { open: true, item, loading: true, error: '', entries: [], format: '', size: 0, skipped: 0, truncated: false, filter: '' };
        try {
            const res = await api('GET', '/api/archive/list', { path: item.path });
            if (this.archive.item !== item) return;
            Object.assign(this.archive, { entries: res.entries, format: res.format, size: res.size, skipped: res.skipped, truncated: res.truncated });
        } catch (e) {
            if (e.status !== 401) this.archive.error = e.message;
        } finally {
            this.archive.loading = false;
        }
    },

    get archiveRows() {
        const q = this.archive.filter.trim().toLowerCase();
        const rows = q ? this.archive.entries.filter((e) => e.path.toLowerCase().includes(q)) : this.archive.entries;
        return rows.slice(0, 3000);
    },

    get archiveFileCount() {
        return this.archive.entries.filter((e) => !e.dir).length;
    },

    downloadArchiveEntry(entry) {
        const a = document.createElement('a');
        a.href = `/api/archive/entry?path=${encodeURIComponent(this.archive.item.path)}&entry=${encodeURIComponent(entry.path)}`;
        a.download = entry.path.split('/').pop();
        document.body.appendChild(a);
        a.click();
        a.remove();
    },

    /**
     * Extract an archive on the server ("here" = next to it, "folder" = into a new folder named after it).
     * The archive is processed on this computer: downloaded once, unpacked, and its files uploaded.
     */
    async extractArchive(item = this.single, mode = 'folder') {
        if (!this.isArchive(item)) return;
        const parent = this.parentOf(item.path);
        let conflict = 'overwrite';

        if (mode === 'here') {
            // Ask before overwriting files that already exist next to the archive.
            try {
                const entries = this.archive.item === item && this.archive.entries.length ? this.archive.entries : (await api('GET', '/api/archive/list', { path: item.path })).entries;
                const top = [...new Set(entries.map((e) => e.path.split('/')[0]))];
                const existing = parent === this.path && this.searchResults === null ? this.items : (await api('GET', '/api/list', { path: parent })).items;
                const clash = top.filter((n) => existing.some((i) => i.name === n));
                if (clash.length) {
                    const choice = await this.confirm({
                        title: 'عناصر موجودة',
                        message: `هذه العناصر موجودة مسبقًا بجانب الأرشيف:\n${clash.slice(0, 8).map((n) => `• ${iso(n)}`).join('\n')}${clash.length > 8 ? '\n…' : ''}\n\nهل تريد استبدال الملفات الموجودة؟`,
                        confirmText: 'استبدال',
                        altText: 'تخطي الموجود',
                        cancelText: 'إلغاء',
                        danger: true,
                        focusCancel: true,
                    });
                    if (choice === false) return;
                    conflict = choice === 'alt' ? 'skip' : 'overwrite';
                }
            } catch (e) {
                return this.fail(e);
            }
        }

        const tid = hexId();
        const t = {
            id: `ar-${tid}`, kind: 'archive', mode: 'server', name: item.name, action: 'extract', size: 0, loaded: 0, status: 'working', phase: null, error: '',
            from: this.absPath(item.path), to: this.absPath(mode === 'folder' ? joinRel(parent, archiveBase(item.name)) : parent), startedAt: Date.now(),
        };
        this.transfers.push(t);
        const tracked = this.transfers.at(-1);
        this.watchLog();

        try {
            const res = await api('POST', '/api/archive/extract', { path: item.path, mode, conflict, tid });
            Object.assign(tracked, { status: 'done', phase: null, to: this.absPath(res.target), loaded: res.bytes ?? 0, size: res.bytes ?? 0 });
            const extras = [
                res.existing && `تم تخطي ${res.existing} ملف موجود`,
                res.unsafe && `تم تجاهل ${res.unsafe} عنصر غير آمن أو رابط رمزي`,
            ].filter(Boolean).join('، ');
            this.notify(`تم استخراج ${res.files} ملف إلى ${iso(this.absPath(res.target))}${extras ? ` (${extras})` : ''}.`, res.unsafe ? 'warning' : 'success', 'اكتمل الاستخراج');
            this.archive.open = false;
            this.scheduleRefresh(parent);
            if (mode === 'folder' && parent === this.path) {
                this.selected = [res.target];
                this.anchor = res.target;
            }
        } catch (e) {
            if (e.status === 401) return;
            Object.assign(tracked, { status: 'error', phase: null, error: e.message });
            this.notify(`فشل استخراج ${iso(item.name)}: ${e.message}`, 'error', 'فشل الاستخراج');
        } finally {
            if (this.logOpen) this.loadLog();
        }
    },

    defaultZipName(items) {
        if (items.length === 1) return `${items[0].dir ? items[0].name : items[0].name.replace(/\.[^.]+$/, '') || items[0].name}.zip`;
        return `${this.path.split('/').filter(Boolean).pop() || 'archive'}.zip`;
    },

    /** Compress the selection into a ZIP file in the current server folder. */
    async compressItems(items = this.selectedItems) {
        if (!items.length) return;
        let name = await this.ask({ title: 'ضغط إلى ZIP', label: 'اسم الملف المضغوط', value: this.defaultZipName(items), confirmText: 'ضغط', selectStem: true });
        if (!name) return;
        if (!/\.zip$/i.test(name)) name += '.zip';
        const dest = this.path;

        const tid = hexId();
        this.transfers.push({
            id: `ar-${tid}`, kind: 'archive', mode: 'server', name, action: 'compress', size: 0, loaded: 0, status: 'working', phase: null, error: '',
            from: items.length === 1 ? this.absPath(items[0].path) : `${items.length} عناصر من ${this.absPath(dest)}`, to: this.absPath(joinRel(dest, name)), startedAt: Date.now(),
        });
        const tracked = this.transfers.at(-1);
        this.watchLog();

        try {
            const res = await api('POST', '/api/archive/compress', { paths: items.map((i) => i.path), dest, name, tid });
            Object.assign(tracked, { status: 'done', phase: null, name: res.name, to: this.absPath(joinRel(dest, res.name)), loaded: res.bytes ?? 0, size: res.bytes ?? 0 });
            this.notify(`تم إنشاء ${iso(res.name)}.`, 'success', 'اكتمل الضغط');
            this.scheduleRefresh(dest);
            if (dest === this.path) {
                this.selected = [joinRel(dest, res.name)];
                this.anchor = this.selected[0];
            }
        } catch (e) {
            if (e.status === 401) return;
            Object.assign(tracked, { status: 'error', phase: null, error: e.message });
            this.notify(`فشل إنشاء الملف المضغوط: ${e.message}`, 'error', 'فشل الضغط');
        } finally {
            if (this.logOpen) this.loadLog();
        }
    },

    /** Download files and folders as one ZIP to this computer's downloads folder. */
    downloadAsZip(items = this.selectedItems) {
        if (!items.length) return;
        const tid = hexId();
        const name = this.defaultZipName(items);
        this.transfers.push({
            id: `dl-${tid}`, tid, kind: 'download', mode: 'browser', name, size: 0, loaded: 0, status: 'waiting', phase: null, error: '',
            from: items.length === 1 ? this.absPath(items[0].path) : `${items.length} عناصر من ${this.absPath(this.path)}`,
            to: 'مجلد التنزيلات في المتصفح', since: Date.now(), startedAt: null,
        });
        this.watchLog();
        const query = items.map((i) => `paths[]=${encodeURIComponent(i.path)}`).join('&');
        const a = document.createElement('a');
        a.href = `/api/archive/zip?${query}&tid=${tid}`;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
    },

    // ------------------------------------------------------------------ folder watch

    watchKey() {
        return `${this.server.username}@${this.server.host}:${this.server.port}`;
    },

    initWatch() {
        const saved = watchPrefs()[this.watchKey()];
        if (saved) {
            this.watch.interval = WATCH_INTERVALS.includes(saved.interval) ? saved.interval : 30;
            this.watch.sound = saved.sound !== false;
            if (typeof saved.path === 'string') this.startWatch(saved.path);
        }
        // Reset the "(3)" in the tab title once the user looks at the page again.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.watchUnseen = 0;
                document.title = baseTitle;
            }
        });
    },

    saveWatchPrefs() {
        const all = watchPrefs();
        all[this.watchKey()] = { path: this.watch.path, interval: this.watch.interval, sound: this.watch.sound };
        try {
            localStorage.setItem(WATCH_KEY, JSON.stringify(all));
        } catch {}
    },

    /** Start watching a folder (the current one by default). The first check only records what is there. */
    startWatch(path = this.path) {
        clearTimeout(watchTimer);
        watchKnown = null;
        Object.assign(this.watch, { path, lastCheck: null, error: '' });
        this.watchNew = [];
        this.watchGrowing = [];
        this.watchMenu = false;
        this.saveWatchPrefs();
        this.watchTick();
    },

    stopWatch() {
        clearTimeout(watchTimer);
        watchKnown = null;
        Object.assign(this.watch, { path: null, lastCheck: null, error: '' });
        this.watchNew = [];
        this.watchGrowing = [];
        this.watchMenu = false;
        this.saveWatchPrefs();
    },

    setWatchInterval(seconds) {
        this.watch.interval = seconds;
        this.saveWatchPrefs();
        if (this.watch.path !== null) {
            clearTimeout(watchTimer);
            watchTimer = setTimeout(() => this.watchTick(), seconds * 1000);
        }
    },

    async watchTick() {
        if (this.watch.path === null || watchBusy) return;
        const path = this.watch.path;
        watchBusy = true;
        try {
            const res = await api('GET', '/api/list', { path });
            if (this.watch.path !== path) return; // watch changed meanwhile
            this.watch.error = '';
            this.watch.lastCheck = Date.now();

            const now = new Map(res.items.map((i) => [i.name, { size: i.size, mtime: i.mtime }]));
            if (watchKnown) {
                const arrived = res.items.filter((i) => !watchKnown.has(i.name) && !TEMP_NAME.test(i.name));
                // Size still changing since the last check → probably still being uploaded by someone.
                this.watchGrowing = res.items
                    .filter((i) => !i.dir && watchKnown.has(i.name) && watchKnown.get(i.name).size !== i.size)
                    .map((i) => i.path);
                if (arrived.length) this.announceNewFiles(path, arrived);
                // Forget "new" marks of files that disappeared.
                this.watchNew = this.watchNew.filter((p) => res.items.some((i) => i.path === p));
            }
            watchKnown = now;

            // Watching the folder on screen: show the new list right away (selection kept).
            listCache.set(path, res.items);
            if (this.path === path && this.searchResults === null && !this.loading) {
                this.items = res.items;
                this.selected = this.selected.filter((p) => res.items.some((i) => i.path === p));
            }
        } catch (e) {
            if (e.status === 401) return this.stopWatchTimerOnly();
            this.watch.error = e.status === 404 || e.status === 403 ? e.message : 'تعذر الفحص، سيُعاد المحاولة.';
            if (e.status === 404) return this.stopWatch(); // the folder is gone
        } finally {
            watchBusy = false;
            if (this.watch.path === path) {
                clearTimeout(watchTimer);
                watchTimer = setTimeout(() => this.watchTick(), this.watch.interval * 1000);
            }
        }
    },

    stopWatchTimerOnly() {
        clearTimeout(watchTimer);
    },

    announceNewFiles(dir, items) {
        this.watchNew = [...new Set([...this.watchNew, ...items.map((i) => i.path)])];
        const names = items.slice(0, 3).map((i) => iso(i.name)).join('، ');
        const more = items.length > 3 ? ` و${items.length - 3} غيرها` : '';
        const what = items.length === 1 ? (items[0].dir ? 'مجلد جديد' : 'ملف جديد') : `${items.length} عناصر جديدة`;
        this.notify(`وصل ${what} إلى ${iso(this.absPath(dir))}: ${names}${more}`, 'info', 'ملفات جديدة', () => this.navigate(dir), 12000);
        if (this.watch.sound) chime();
        if (document.hidden) {
            this.watchUnseen += items.length;
            document.title = `(${this.watchUnseen}) ${baseTitle}`;
        }
    },

    isWatchNew(item) {
        return this.watchNew.includes(item.path);
    },

    isGrowing(item) {
        return this.watchGrowing.includes(item.path);
    },

    watchStatus() {
        if (this.watch.path === null) return '';
        const t = this.watch.lastCheck ? new Date(this.watch.lastCheck) : null;
        const p = (n) => String(n).padStart(2, '0');
        return t ? `آخر فحص ${p(t.getHours())}:${p(t.getMinutes())}:${p(t.getSeconds())}` : 'جارٍ الفحص…';
    },

    // ------------------------------------------------------------------ switch server

    toggleServerMenu() {
        if (!this.serverMenu) {
            try {
                this.savedServers = JSON.parse(localStorage.getItem('sftp.savedServers')) ?? [];
            } catch {
                this.savedServers = [];
            }
        }
        this.serverMenu = !this.serverMenu;
    },

    isCurrentServer(s) {
        return s.host === this.server.host && Number(s.port) === Number(this.server.port) && s.username === this.server.username;
    },

    /** Open the connection window for another server (the current one is replaced once it connects). */
    async switchServer(target) {
        this.serverMenu = false;
        if (this.viewer.dirty || this.transfers.some((t) => ACTIVE.includes(t.status) && t.mode !== 'browser')) {
            const ok = await this.confirm({
                title: 'التبديل إلى سيرفر آخر',
                message: 'هناك تغييرات غير محفوظة أو عمليات نقل جارية، وستتوقف عند الاتصال بسيرفر آخر. متابعة؟',
                confirmText: 'متابعة',
                danger: true,
            });
            if (!ok) return;
            this.viewer.dirty = false;
            this._leaving = true;
        }
        window.dispatchEvent(new CustomEvent('switch-server', { detail: target }));
    },

    // ------------------------------------------------------------------ transfer log & notifications

    /** In-app toast, plus a desktop notification when enabled and the tab is in the background. */
    notify(message, type = 'success', title = '', onClick = null, timeout = 3500) {
        this.toast(message, type, timeout);
        if (type === 'error') this.logFailures++;
        if (this.desktopNotify && document.hidden && 'Notification' in window && Notification.permission === 'granted') {
            try {
                const n = new Notification(title || 'مدير ملفات SFTP', { body: message.replace(/[\u2068\u2069]/g, ''), tag: `sftp-${randomId()}` });
                n.onclick = () => {
                    window.focus();
                    onClick?.();
                    n.close();
                };
            } catch {}
        }
    },

    async toggleDesktopNotify() {
        if (!('Notification' in window)) return this.toast('المتصفح لا يدعم إشعارات سطح المكتب (تحتاج HTTPS).', 'warning');
        if (this.desktopNotify) {
            this.desktopNotify = false;
        } else {
            const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            this.desktopNotify = perm === 'granted';
            if (!this.desktopNotify) this.toast('لم يتم السماح بالإشعارات في المتصفح.', 'warning');
        }
        try {
            localStorage.setItem('sftp.desktopNotify', this.desktopNotify ? '1' : '0');
        } catch {}
    },

    openLog() {
        this.logOpen = true;
        this.logFailures = 0;
        this.loadLog();
    },

    async loadLog() {
        this.logLoading = true;
        try {
            this.logEntries = (await api('GET', '/api/log')).entries;
            this.checkPendingDownloads();
        } catch (e) {
            if (e.status !== 401) this.fail(e);
        } finally {
            this.logLoading = false;
        }
    },

    /**
     * Poll the (cheap, SFTP-free) log endpoint while browser downloads run: it reports how many bytes
     * the server has sent (progress bar) and the final result (notification).
     */
    watchLog() {
        if (logTimer) return;
        const tick = async () => {
            if (!this.transfers.some((t) => (t.mode === 'browser' || t.mode === 'server') && ACTIVE.includes(t.status))) {
                logTimer = null;
                return;
            }
            try {
                this.logEntries = (await api('GET', '/api/log')).entries;
                this.checkPendingDownloads();
            } catch (e) {
                if (e.status === 401) return (logTimer = null);
            }
            logTimer = setTimeout(tick, 1000);
        };
        logTimer = setTimeout(tick, 700);
    },

    checkPendingDownloads() {
        for (const t of this.transfers) {
            if (t.mode === 'server' && ACTIVE.includes(t.status)) {
                // Archive work: only the progress comes from the log; the request itself reports the result.
                const entry = this.logEntries.find((e) => e.id === t.id);
                if (entry?.status === 'started' && entry.phase) {
                    if (t.phase !== entry.phase) t.startedAt = Date.now();
                    Object.assign(t, { phase: entry.phase, loaded: entry.transferred ?? 0, size: entry.progressTotal ?? 0, baseLoaded: 0 });
                }
                continue;
            }
            if (t.mode !== 'browser' || !ACTIVE.includes(t.status)) continue;
            const entry = this.logEntries.find((e) => e.id === t.id);
            if (!entry) {
                if (Date.now() - t.since > 30000) {
                    t.status = 'unknown';
                    this.toast(`تعذر التحقق من نتيجة تنزيل ${iso(t.name)}.`, 'warning');
                }
                continue;
            }
            if (entry.size != null) t.size = entry.size;
            if (entry.status === 'started') {
                if (t.status === 'waiting') {
                    t.status = 'downloading';
                    t.startedAt = Date.now();
                }
                if (entry.phase) {
                    // ZIP downloads: collect → compress → send, each with its own progress.
                    if (t.phase !== entry.phase) t.startedAt = Date.now();
                    Object.assign(t, { phase: entry.phase, size: entry.progressTotal ?? t.size, baseLoaded: 0 });
                }
                t.loaded = entry.transferred ?? t.loaded;
                continue;
            }
            if (entry.status === 'success') {
                Object.assign(t, { status: 'done', phase: null, size: entry.size ?? t.size, loaded: entry.size ?? t.size });
                this.notify(`تم تنزيل ${iso(t.name)} إلى جهازك بنجاح (${iso(formatSize(entry.size))}).`, 'success', 'اكتمل التنزيل');
            } else if (entry.status === 'canceled') {
                t.status = 'canceled';
                this.toast(`تم إلغاء تنزيل ${iso(t.name)}.`, 'warning');
            } else {
                Object.assign(t, { status: 'error', error: entry.message || 'خطأ غير معروف' });
                this.notify(`فشل تنزيل ${iso(t.name)}: ${t.error}`, 'error', 'فشل التنزيل');
            }
        }
    },

    async clearLog() {
        const ok = await this.confirm({ title: 'مسح سجل النقل', message: 'هل تريد مسح جميع سجلات النقل لهذه الجلسة؟', confirmText: 'مسح', danger: true });
        if (!ok) return;
        try {
            this.toast((await api('POST', '/api/log/clear')).message);
            this.logEntries = [];
        } catch (e) {
            this.fail(e);
        }
    },

    exportLog() {
        const statusText = { success: 'نجح', failed: 'فشل', canceled: 'ملغى', started: 'جارٍ' };
        const lines = [...this.logEntries].reverse().map((e) =>
            [
                this.formatTime(e.started),
                this.logTypeLabel(e),
                statusText[e.status] ?? e.status,
                e.from ? `من: ${this.sideLabel(e.from)} ${e.from.path}` : this.absPath(e.path ?? e.name),
                e.to ? `إلى: ${this.sideLabel(e.to)} ${e.to.path}` : '',
                e.size != null ? `${e.size} bytes` : '', e.duration != null ? `${e.duration}s` : '', e.message ?? '']
                .join('\t'),
        );
        const header = `# سجل النقل — ${this.server.name} (${this.server.username}@${this.server.host})\n`;
        const blob = new Blob([header + lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `transfer-log-${new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-')}.txt`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },

    get logStats() {
        const count = (s) => this.logEntries.filter((e) => e.status === s).length;
        return { success: count('success'), failed: count('failed'), canceled: count('canceled'), started: count('started') };
    },

    sideLabel(end) {
        if (!end) return '';
        if (end.side === 'server') return 'السيرفر';
        if (end.side === 'editor') return 'المحرر';
        return end.note === 'downloads' ? 'جهازك · مجلد التنزيلات' : 'جهازك';
    },

    logTypeLabel(entry) {
        return {
            upload: 'رفع: الجهاز ← السيرفر', download: 'تنزيل: السيرفر ← الجهاز', save: 'حفظ من المحرر ← السيرفر',
            extract: 'استخراج أرشيف على السيرفر', compress: 'ضغط إلى ZIP على السيرفر',
        }[entry.type] ?? entry.type;
    },

    formatTime(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
    },

    formatDuration(sec) {
        if (sec == null) return '';
        return sec < 60 ? `${sec.toFixed(1)}s` : `${Math.floor(sec / 60)}m ${Math.round(sec % 60)}s`;
    },

    // ------------------------------------------------------------------ misc

    toggleTheme() {
        this.dark = toggleTheme() === 'dark';
        editor?.setTheme(this.dark);
    },

    async disconnect() {
        if (this.viewer.dirty || this.transfers.some((t) => ACTIVE.includes(t.status) && t.mode !== 'browser')) {
            const ok = await this.confirm({
                title: 'قطع الاتصال',
                message: 'هناك تغييرات غير محفوظة أو عمليات رفع جارية. هل تريد قطع الاتصال على أي حال؟',
                confirmText: 'قطع الاتصال',
                danger: true,
            });
            if (!ok) return;
            this.viewer.dirty = false;
            this.transfers = [];
        }
        this.$refs.disconnectForm.submit();
    },

    onKey(e) {
        const mod = e.ctrlKey || e.metaKey;
        const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;

        if (this.logOpen && key === 'Escape' && !this.dialog.open) return (this.logOpen = false);
        if (this.archive.open && !this.dialog.open) {
            if (key === 'Escape') this.archive.open = false;
            return;
        }
        if (this.dialog.open) {
            if (key === 'Escape') this.closeDialog(false);
            return;
        }
        if (this.props) {
            if (key === 'Escape' || key === 'Enter') this.props = null;
            return;
        }
        if (this.viewer.open) {
            if (mod && key === 's') {
                e.preventDefault();
                this.save();
            } else if (key === 'Escape' && this.viewer.kind !== 'editor') {
                this.closeViewer();
            }
            return;
        }
        if (this.menu.open && key === 'Escape') return this.closeMenu();

        if (mod && key === 'f') {
            e.preventDefault();
            this.$refs.search.focus();
            this.$refs.search.select();
            return;
        }

        const t = e.target;
        if (t && (['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName) || t.isContentEditable)) return;

        if (mod && key === 'c') this.copy('copy');
        else if (mod && key === 'x') this.copy('cut');
        else if (mod && key === 'v') this.paste();
        else if (mod && key === 'a') {
            e.preventDefault();
            this.selectAll();
        } else if (key === 'Delete' || (e.metaKey && key === 'Backspace')) {
            e.preventDefault();
            this.remove();
        } else if (key === 'F2') {
            e.preventDefault();
            this.rename();
        } else if (key === 'F5') {
            e.preventDefault();
            this.refresh();
        } else if (key === 'Backspace') {
            e.preventDefault();
            this.searchResults !== null ? this.clearSearch() : this.goBack();
        } else if (key === 'Enter') {
            if (this.single) this.open(this.single);
        } else if (key === 'ArrowDown') {
            e.preventDefault();
            this.moveSelection(1);
        } else if (key === 'ArrowUp') {
            e.preventDefault();
            this.moveSelection(-1);
        } else if (key === 'Escape') {
            if (this.searchResults !== null || this.filter) this.clearSearch();
            else this.selected = [];
        }
    },
});
