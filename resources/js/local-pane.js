// Local files panel (like FileZilla's left pane).
//
// Browsers cannot read the local disk freely. Two modes:
//  - 'fsa'      (Chrome/Edge, HTTPS or localhost): the File System Access API gives a real, browsable folder
//               the user picked; files can be uploaded from it AND downloaded into it (with progress).
//  - 'snapshot' (other browsers): a folder chosen via <input webkitdirectory>; read-only list for uploading.
import { api, ApiError, sessionExpired } from './api';
import { drag, dragKind, formatSize, hexId, iso, joinRel, parentRel, LOCAL_TYPE } from './utils';

// Non-reactive state (must not be wrapped in Alpine proxies).
let dirStack = []; // FileSystemDirectoryHandle from the chosen root to the current folder
let entryHandles = new Map(); // name -> handle, for the current folder
let snapshot = null; // { dirs: Map<relPath, { dirs: Set<string>, files: Map<string, File> }> }
let pendingRoot = null; // remembered handle waiting for the user to re-grant permission
const localTargets = new Map(); // transfer id -> destination FileSystemDirectoryHandle
const downloadControllers = new Map(); // transfer id -> AbortController
const MAX_LOCAL_ENTRIES = 5000;

// ---------------------------------------------------------------- folder comparison (sync)
const MAX_SYNC_FILES = 20000;
const SYNC_IGNORE_KEY = 'sftp.syncIgnore';
const DEFAULT_SYNC_IGNORE = '.git, node_modules, .DS_Store, Thumbs.db, .idea, .vscode';
const syncSources = new Map(); // rel -> FileSystemFileHandle (fsa) or File (snapshot), for the upload step
// Dates within this many seconds count as equal (file systems store times with different precision).
const MTIME_TOLERANCE = 2;
// Same size but a different date: compare the content by SHA-256 (up to this size; bigger files keep the date rule).
const MAX_HASH_BYTES = 50 * 1024 * 1024;
const HASH_BATCH_FILES = 200;
const HASH_BATCH_BYTES = 100 * 1024 * 1024;
const hex = (buf) => Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');
const SYNC_ORDER = { new: 0, changed: 1, 'remote-newer': 2, conflict: 3, 'remote-only': 4, same: 5 };

/** "node_modules, *.log" → a test on each path segment. */
function ignoreMatcher(patterns) {
    const regexes = patterns
        .split(/[,\n]/)
        .map((p) => p.trim())
        .filter(Boolean)
        .map((p) => new RegExp('^' + p.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$', 'i'));
    return (rel) => rel.split('/').some((seg) => regexes.some((r) => r.test(seg)));
}

const fsaSupported = () => typeof window.showDirectoryPicker === 'function' && window.isSecureContext;

// The chosen folder handle is remembered in IndexedDB (Chrome/Edge). It is only a *reference*
// to the folder — no file contents — and the browser still asks for permission again later.
function idb(mode, fn) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open('sftp-file-manager', 1);
        open.onupgradeneeded = () => open.result.createObjectStore('kv');
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const tx = open.result.transaction('kv', mode);
            const req = fn(tx.objectStore('kv'));
            tx.oncomplete = () => resolve(req?.result);
            tx.onerror = () => reject(tx.error);
        };
    });
}
const idbGet = (key) => idb('readonly', (s) => s.get(key)).catch(() => null);
const idbSet = (key, value) => idb('readwrite', (s) => s.put(value, key)).catch(() => {});

function pref(key, fallback) {
    try {
        const v = localStorage.getItem(key);
        return v === null ? fallback : v === '1';
    } catch {
        return fallback;
    }
}

export default () => ({
    localVisible: pref('sftp.localPane', true),
    localFsa: fsaSupported(),
    localMode: null, // null | 'fsa' | 'snapshot'
    localRootName: '',
    localPath: [], // folder names below the root
    localItems: [],
    localSelected: [],
    localAnchor: null,
    localLoading: false,
    localError: '',
    localRestorable: '', // name of a remembered folder that needs permission again
    localWritable: false,
    localDropTarget: null, // '' = current folder, or a sub-folder name, while dragging server files in

    async initLocal() {
        if (!this.localFsa) return;
        const handle = await idbGet('localRoot');
        if (!handle) return;
        try {
            if ((await handle.queryPermission({ mode: 'readwrite' })) === 'granted') return this.openLocalRoot(handle);
        } catch {}
        pendingRoot = handle;
        this.localRestorable = handle.name;
    },

    toggleLocal() {
        this.localVisible = !this.localVisible;
        try {
            localStorage.setItem('sftp.localPane', this.localVisible ? '1' : '0');
        } catch {}
    },

    async pickLocalFolder() {
        if (!this.localFsa) {
            this.$refs.localInput.value = '';
            return this.$refs.localInput.click();
        }
        try {
            const handle = await window.showDirectoryPicker({ id: 'sftp-local', mode: 'readwrite' });
            await this.openLocalRoot(handle);
            idbSet('localRoot', handle);
        } catch (e) {
            if (e.name !== 'AbortError') this.fail(e);
        }
    },

    async restoreLocal() {
        if (!pendingRoot) return;
        try {
            if ((await pendingRoot.requestPermission({ mode: 'readwrite' })) === 'granted') {
                await this.openLocalRoot(pendingRoot);
                pendingRoot = null;
            }
        } catch (e) {
            this.fail(e);
        }
    },

    async openLocalRoot(handle) {
        dirStack = [handle];
        snapshot = null;
        this.localMode = 'fsa';
        this.localRootName = handle.name || 'المجلد المحلي';
        this.localPath = [];
        this.localSelected = [];
        this.localRestorable = '';
        this.localWritable = (await handle.queryPermission({ mode: 'readwrite' })) === 'granted';
        await this.loadLocal();
    },

    /** Fallback for browsers without the File System Access API: a read-only snapshot of a folder. */
    onLocalSnapshot(fileList) {
        const files = [...fileList];
        if (!files.length) return;
        const dirs = new Map();
        const node = (rel) => {
            if (!dirs.has(rel)) dirs.set(rel, { dirs: new Set(), files: new Map() });
            return dirs.get(rel);
        };
        let rootName = '';
        for (const f of files) {
            const parts = (f.webkitRelativePath || f.name).split('/');
            rootName ||= parts.length > 1 ? parts[0] : '';
            const inner = parts.length > 1 ? parts.slice(1) : parts;
            for (let i = 0; i < inner.length - 1; i++) node(inner.slice(0, i).join('/')).dirs.add(inner[i]);
            node(inner.slice(0, -1).join('/')).files.set(inner.at(-1), f);
        }
        node('');
        snapshot = { dirs };
        dirStack = [];
        this.localMode = 'snapshot';
        this.localWritable = false;
        this.localRootName = rootName || 'المجلد المحلي';
        this.localPath = [];
        this.localSelected = [];
        this.loadLocal();
    },

    async loadLocal() {
        if (!this.localMode) return;
        this.localLoading = true;
        this.localError = '';
        entryHandles = new Map();
        const items = [];
        try {
            if (this.localMode === 'fsa') {
                for await (const [name, handle] of dirStack.at(-1).entries()) {
                    entryHandles.set(name, handle);
                    if (handle.kind === 'directory') {
                        items.push({ name, dir: true, size: null, mtime: null });
                    } else {
                        let file = null;
                        try {
                            file = await handle.getFile();
                        } catch {}
                        items.push({ name, dir: false, size: file?.size ?? null, mtime: file ? Math.floor(file.lastModified / 1000) : null });
                    }
                    if (items.length >= MAX_LOCAL_ENTRIES) break;
                }
            } else {
                const n = snapshot.dirs.get(this.localPath.join('/'));
                for (const name of n?.dirs ?? []) items.push({ name, dir: true, size: null, mtime: null });
                for (const [name, f] of n?.files ?? []) items.push({ name, dir: false, size: f.size, mtime: Math.floor(f.lastModified / 1000) });
            }
        } catch (e) {
            this.localError = e.name === 'NotAllowedError' ? 'لا توجد صلاحية لقراءة هذا المجلد.' : e.message;
        } finally {
            this.localItems = items.sort((a, b) => (a.dir !== b.dir ? (a.dir ? -1 : 1) : a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' })));
            this.localSelected = this.localSelected.filter((n) => items.some((i) => i.name === n));
            this.localLoading = false;
        }
    },

    get localDisplayPath() {
        return [this.localRootName, ...this.localPath].join('/');
    },

    get localSelectedItems() {
        return this.localItems.filter((i) => this.localSelected.includes(i.name));
    },

    localOpen(item) {
        if (!item.dir) return this.uploadLocal([item]); // like FileZilla: double-click a local file = upload
        if (this.localMode === 'fsa') dirStack.push(entryHandles.get(item.name));
        this.localPath = [...this.localPath, item.name];
        this.localSelected = [];
        this.loadLocal();
    },

    localGoTo(depth) {
        // depth 0 = the chosen root folder
        this.localPath = this.localPath.slice(0, depth);
        if (this.localMode === 'fsa') dirStack = dirStack.slice(0, depth + 1);
        this.localSelected = [];
        this.loadLocal();
    },

    localUp() {
        if (this.localPath.length) this.localGoTo(this.localPath.length - 1);
    },

    localClick(e, item) {
        const list = this.localItems;
        if (e.shiftKey && this.localAnchor) {
            const a = list.findIndex((i) => i.name === this.localAnchor);
            const b = list.findIndex((i) => i.name === item.name);
            if (a !== -1 && b !== -1) {
                this.localSelected = list.slice(Math.min(a, b), Math.max(a, b) + 1).map((i) => i.name);
                return;
            }
        }
        if (e.ctrlKey || e.metaKey) {
            this.localSelected = this.localSelected.includes(item.name) ? this.localSelected.filter((n) => n !== item.name) : [...this.localSelected, item.name];
        } else {
            this.localSelected = [item.name];
        }
        this.localAnchor = item.name;
    },

    /** Read the selected local items (folders recursively) into the shape startUploads() expects. */
    async collectLocal(items) {
        const files = [];
        const dirs = [];
        const base = this.localDisplayPath;
        const add = (file, rel) => {
            if (files.length >= MAX_LOCAL_ENTRIES) throw new Error(`لا يمكن رفع أكثر من ${MAX_LOCAL_ENTRIES} ملف دفعة واحدة.`);
            files.push({ file, rel, source: `${base}/${rel}` });
        };

        if (this.localMode === 'fsa') {
            const walk = async (dirHandle, rel) => {
                dirs.push(rel);
                for await (const [name, h] of dirHandle.entries()) {
                    if (h.kind === 'directory') await walk(h, `${rel}/${name}`);
                    else add(await h.getFile(), `${rel}/${name}`);
                }
            };
            for (const item of items) {
                const h = entryHandles.get(item.name);
                if (item.dir) await walk(h, item.name);
                else add(await h.getFile(), item.name);
            }
        } else {
            const here = this.localPath.join('/');
            const walk = (rel) => {
                dirs.push(rel);
                const n = snapshot.dirs.get(joinRel(here, rel));
                for (const [name, f] of n?.files ?? []) add(f, `${rel}/${name}`);
                for (const name of n?.dirs ?? []) walk(`${rel}/${name}`);
            };
            for (const item of items) {
                if (item.dir) walk(item.name);
                else add(snapshot.dirs.get(here).files.get(item.name), item.name);
            }
        }
        return { files, dirs };
    },

    async uploadLocal(items = this.localSelectedItems, targetDir = this.path) {
        if (!items.length) return this.toast('حدد ملفات أو مجلدات من اللوحة المحلية أولًا.', 'warning');
        try {
            const { files, dirs } = await this.collectLocal(items);
            await this.startUploads(files, dirs, targetDir);
        } catch (e) {
            this.fail(e);
        }
    },

    // ------------------------------------------------------------ drag & drop between panels

    onLocalDragStart(e, item) {
        if (!this.localSelected.includes(item.name)) this.localSelected = [item.name];
        drag.payload = { source: 'local', items: this.localSelectedItems };
        e.dataTransfer.setData(LOCAL_TYPE, '1');
        e.dataTransfer.effectAllowed = 'copy';
    },

    onLocalDragOver(e, item = null) {
        if (dragKind(e) !== 'remote' || this.localMode !== 'fsa') return;
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'copy';
        this.localDropTarget = item?.dir ? item.name : '';
    },

    onLocalDragLeave(e) {
        if (!e.currentTarget.contains(e.relatedTarget)) this.localDropTarget = null;
    },

    async onLocalDrop(e, item = null) {
        const payload = drag.payload;
        this.localDropTarget = null;
        if (dragKind(e) !== 'remote' || !payload || this.localMode !== 'fsa') return;
        e.preventDefault();
        e.stopPropagation();
        const sub = item?.dir ? item.name : null;
        const dirHandle = sub ? entryHandles.get(sub) : dirStack.at(-1);
        await this.downloadToLocal(payload.items, dirHandle, sub ? [...this.localPath, sub] : [...this.localPath]);
    },

    /** Download server files into the current local folder (context menu / toolbar). */
    downloadHere(items = this.selectedItems) {
        if (this.localMode !== 'fsa') {
            return this.toast('اختر مجلدًا محليًا أولًا من اللوحة المحلية (يتطلب Chrome أو Edge).', 'warning');
        }
        return this.downloadToLocal(items, dirStack.at(-1), [...this.localPath]);
    },

    /** Save server files into a local folder, streaming to disk with a real progress bar. */
    async downloadToLocal(items, dirHandle, relParts) {
        const files = items.filter((i) => !i.dir);
        if (files.length < items.length) this.toast('تنزيل المجلدات غير مدعوم؛ تم تنزيل الملفات فقط.', 'warning');
        if (!files.length) return;

        try {
            if ((await dirHandle.queryPermission({ mode: 'readwrite' })) !== 'granted' && (await dirHandle.requestPermission({ mode: 'readwrite' })) !== 'granted') {
                return this.toast('لم يتم السماح بالكتابة في المجلد المحلي.', 'warning');
            }
        } catch (e) {
            return this.fail(e);
        }
        this.localWritable = true;

        const conflicts = [];
        for (const f of files) {
            try {
                await dirHandle.getFileHandle(f.name);
                conflicts.push(f.name);
            } catch {}
        }
        let overwrite = false;
        if (conflicts.length) {
            overwrite = await this.confirm({
                title: 'ملفات موجودة على جهازك',
                message: `الملفات التالية موجودة في المجلد المحلي:\n${conflicts.slice(0, 8).map((n) => `• ${iso(n)}`).join('\n')}\n\nاستبدالها؟ "تخطي" ينزّل الملفات الأخرى فقط.`,
                confirmText: 'استبدال',
                cancelText: 'تخطي',
                danger: true,
            });
        }

        const localDir = [this.localRootName, ...relParts].join('/');
        for (const f of files) {
            if (!overwrite && conflicts.includes(f.name)) continue;
            const tid = hexId();
            const id = `dl-${tid}`;
            localTargets.set(id, dirHandle);
            this.transfers.push({
                id, tid, kind: 'download', mode: 'local', name: f.name, size: f.size ?? 0, loaded: 0, status: 'queued', error: '',
                path: f.path, from: this.absPath(f.path), to: `${localDir}/${f.name}`, localDir: relParts.join('/'), startedAt: null,
            });
        }
        this.processLocalDownloads();
    },

    async processLocalDownloads() {
        if (this._downloading) return;
        this._downloading = true;
        try {
            let next;
            while ((next = this.transfers.find((t) => t.kind === 'download' && t.mode === 'local' && t.status === 'queued'))) {
                await this.downloadOne(next);
            }
        } finally {
            this._downloading = false;
        }
    },

    async downloadOne(t) {
        const dirHandle = localTargets.get(t.id);
        const controller = new AbortController();
        downloadControllers.set(t.id, controller);
        t.status = 'downloading';
        t.startedAt = Date.now();
        let writable = null;

        try {
            const url = `/api/download?path=${encodeURIComponent(t.path)}&tid=${t.tid}&dest=${encodeURIComponent(t.to)}`;
            const res = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) {
                let body = {};
                try {
                    body = await res.json();
                } catch {}
                if (body.reconnect) sessionExpired(body.message);
                throw new ApiError(body.message || 'تعذر تنزيل الملف.', res.status);
            }
            t.size = Number(res.headers.get('Content-Length')) || t.size;

            // createWritable() writes to a temporary file; the real file is replaced only on close().
            const fileHandle = await dirHandle.getFileHandle(t.name, { create: true });
            writable = await fileHandle.createWritable();
            const reader = res.body.getReader();
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                await writable.write(value);
                t.loaded += value.byteLength;
            }
            if (t.size && t.loaded !== t.size) throw new Error('انقطع التنزيل قبل اكتماله.');
            await writable.close();
            writable = null;

            t.status = 'done';
            this.notify(`تم تنزيل ${iso(t.name)} إلى ${iso(t.to)} (${iso(formatSize(t.loaded))}).`, 'success', 'اكتمل التنزيل');
            if (this.localPath.join('/') === t.localDir) this.loadLocal();
        } catch (e) {
            if (writable) await writable.abort().catch(() => {});
            const canceled = e.name === 'AbortError';
            t.status = canceled ? 'canceled' : 'error';
            t.error = canceled ? '' : e.message;
            if (canceled) this.toast(`تم إلغاء تنزيل ${iso(t.name)}.`, 'warning');
            else this.notify(`فشل تنزيل ${iso(t.name)}: ${e.message}`, 'error', 'فشل التنزيل');
        } finally {
            downloadControllers.delete(t.id);
            localTargets.delete(t.id);
            if (this.logOpen) setTimeout(() => this.loadLog(), 300);
        }
    },

    // ------------------------------------------------------------ compare & sync (local → server)

    syncOpen: false,
    syncLoading: false,
    syncError: '',
    syncRows: [],
    syncFilter: 'todo',
    syncTruncated: false,
    syncTarget: '',
    syncRemoteDirs: [],
    syncProgress: '',
    syncIgnore: (() => {
        try {
            return localStorage.getItem(SYNC_IGNORE_KEY) ?? DEFAULT_SYNC_IGNORE;
        } catch {
            return DEFAULT_SYNC_IGNORE;
        }
    })(),

    async openSync() {
        if (!this.localMode) return this.toast('اختر مجلدًا محليًا أولًا من اللوحة المحلية.', 'warning');
        if (this.searchResults !== null) this.clearSearch();
        this.syncOpen = true;
        this.syncFilter = 'todo';
        await this.runCompare();
    },

    /** Every file below the current local folder: [{ rel, size, mtime(ms) }] and the folder list. */
    async walkLocal(skip) {
        syncSources.clear();
        const files = [];
        const dirs = [];
        const add = (rel, size, mtime, source) => {
            if (files.length >= MAX_SYNC_FILES) throw new Error(`المجلد المحلي يحتوي أكثر من ${MAX_SYNC_FILES} ملف. استخدم الاستثناءات أو اختر مجلدًا أصغر.`);
            files.push({ rel, size, mtime });
            syncSources.set(rel, source);
        };

        if (this.localMode === 'fsa') {
            const walk = async (dirHandle, prefix) => {
                for await (const [name, h] of dirHandle.entries()) {
                    const rel = joinRel(prefix, name);
                    if (skip(rel)) continue;
                    if (h.kind === 'directory') {
                        dirs.push(rel);
                        await walk(h, rel);
                    } else {
                        const f = await h.getFile();
                        add(rel, f.size, f.lastModified, h);
                    }
                }
            };
            await walk(dirStack.at(-1), '');
        } else {
            const base = this.localPath.join('/');
            const walk = (prefix) => {
                const n = snapshot.dirs.get(joinRel(base, prefix));
                for (const [name, f] of n?.files ?? []) {
                    const rel = joinRel(prefix, name);
                    if (!skip(rel)) add(rel, f.size, f.lastModified, f);
                }
                for (const name of n?.dirs ?? []) {
                    const rel = joinRel(prefix, name);
                    if (skip(rel)) continue;
                    dirs.push(rel);
                    walk(rel);
                }
            };
            walk('');
        }
        return { files, dirs };
    },

    async runCompare() {
        this.syncLoading = true;
        this.syncError = '';
        this.syncRows = [];
        this.syncTarget = this.path;
        try {
            localStorage.setItem(SYNC_IGNORE_KEY, this.syncIgnore);
        } catch {}

        try {
            const skip = ignoreMatcher(this.syncIgnore);
            const local = await this.walkLocal(skip);
            const remote = await api('POST', '/api/tree', { path: this.syncTarget, dirs: local.dirs });
            this.syncTruncated = remote.truncated;

            const remoteMap = new Map(remote.items.filter((i) => !skip(i.path)).map((i) => [i.path, i]));
            this.syncRemoteDirs = remote.items.filter((i) => i.dir).map((i) => i.path);
            const rows = [];

            for (const f of local.files) {
                const r = remoteMap.get(f.rel);
                remoteMap.delete(f.rel);
                let status;
                if (!r) {
                    status = 'new';
                } else if (r.dir) {
                    status = 'conflict'; // a file here, a folder with the same name on the server
                } else {
                    const localSec = Math.floor(f.mtime / 1000);
                    const remoteSec = r.mtime ?? 0;
                    if (f.size !== r.size) status = localSec >= remoteSec - MTIME_TOLERANCE ? 'changed' : 'remote-newer';
                    else status = localSec > remoteSec + MTIME_TOLERANCE ? 'changed' : 'same';
                }
                rows.push({ rel: f.rel, status, local: f, remote: r ?? null, checked: status === 'new' || status === 'changed' });
            }

            // A different date alone doesn't mean different content (e.g. a file saved without changes):
            // for same-size files, compare the actual content instead.
            const suspects = rows.filter(
                (r) => r.remote && !r.remote.dir && r.local.size === r.remote.size && r.local.size <= MAX_HASH_BYTES
                    && Math.abs(Math.floor(r.local.mtime / 1000) - (r.remote.mtime ?? 0)) > MTIME_TOLERANCE,
            );
            if (suspects.length) await this.verifyContent(suspects);

            // What is only on the server (folders that also exist locally are not "only on the server").
            for (const d of local.dirs) remoteMap.delete(d);
            for (const r of remoteMap.values()) rows.push({ rel: r.path, status: 'remote-only', local: null, remote: r, checked: false });

            rows.sort((a, b) => SYNC_ORDER[a.status] - SYNC_ORDER[b.status] || a.rel.localeCompare(b.rel));
            this.syncRows = rows;
        } catch (e) {
            if (e.status !== 401) this.syncError = e.message;
        } finally {
            this.syncLoading = false;
            this.syncProgress = '';
        }
    },

    /** Hash the local and server copies of these rows and set their status from the content. Nothing is stored. */
    async verifyContent(rows) {
        let done = 0;
        const total = rows.length;
        this.syncProgress = `التحقق من محتوى الملفات: 0 / ${total}`;

        for (let i = 0; i < rows.length; ) {
            // Batch by count and size so each request stays reasonable.
            const batch = [];
            let bytes = 0;
            while (i < rows.length && batch.length < HASH_BATCH_FILES && (bytes === 0 || bytes + rows[i].local.size <= HASH_BATCH_BYTES)) {
                bytes += rows[i].local.size;
                batch.push(rows[i++]);
            }

            const [{ hashes }, localHashes] = await Promise.all([
                api('POST', '/api/hashes', { path: this.syncTarget, files: batch.map((r) => r.rel) }),
                (async () => {
                    const out = {};
                    for (const r of batch) {
                        const src = syncSources.get(r.rel);
                        const file = src instanceof File ? src : await src.getFile();
                        out[r.rel] = hex(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()));
                    }
                    return out;
                })(),
            ]);

            for (const r of batch) {
                const remoteHash = hashes[r.rel];
                if (!remoteHash) continue; // unreadable on the server: keep the size + date verdict
                r.verified = true;
                if (remoteHash === localHashes[r.rel]) {
                    r.status = 'same';
                } else {
                    r.status = Math.floor(r.local.mtime / 1000) >= (r.remote.mtime ?? 0) ? 'changed' : 'remote-newer';
                }
                r.checked = r.status === 'changed';
            }
            done += batch.length;
            this.syncProgress = `التحقق من محتوى الملفات: ${done} / ${total}`;
        }
        this.syncProgress = '';
    },

    get syncCounts() {
        const c = { new: 0, changed: 0, 'remote-newer': 0, conflict: 0, 'remote-only': 0, same: 0 };
        for (const r of this.syncRows) c[r.status]++;
        return c;
    },

    get syncVisibleRows() {
        const f = this.syncFilter;
        const rows = f === 'all' ? this.syncRows : f === 'todo' ? this.syncRows.filter((r) => ['new', 'changed', 'remote-newer', 'conflict'].includes(r.status)) : this.syncRows.filter((r) => r.status === f);
        return rows.slice(0, 2000); // keep the table responsive; counts above are complete
    },

    get syncSelected() {
        return this.syncRows.filter((r) => r.checked && ['new', 'changed', 'remote-newer'].includes(r.status));
    },

    get syncSelectedBytes() {
        return this.syncSelected.reduce((sum, r) => sum + (r.local?.size ?? 0), 0);
    },

    syncStatusLabel(status) {
        return {
            new: 'جديد', changed: 'معدّل', 'remote-newer': 'السيرفر أحدث', conflict: 'تعارض', 'remote-only': 'على السيرفر فقط', same: 'متطابق',
        }[status];
    },

    toggleSyncAll(checked) {
        for (const r of this.syncVisibleRows) if (['new', 'changed', 'remote-newer'].includes(r.status)) r.checked = checked;
    },

    /** Upload the checked files (new + changed) into the server folder. Nothing is ever deleted. */
    async runSync() {
        const rows = this.syncSelected;
        if (!rows.length) return;
        try {
            const files = await Promise.all(
                rows.map(async (r) => {
                    const src = syncSources.get(r.rel);
                    const file = src instanceof File ? src : await src.getFile();
                    return { file, rel: r.rel, source: `${this.localDisplayPath}/${r.rel}` };
                }),
            );
            // Folders to create first: every parent of a new file that the server doesn't have yet.
            const have = new Set(this.syncRemoteDirs);
            const dirs = new Set();
            for (const r of rows) {
                for (let p = parentRel(r.rel); p; p = parentRel(p)) if (!have.has(p)) dirs.add(p);
            }
            this.syncOpen = false;
            await this.startUploads(files, [...dirs], this.syncTarget, { overwrite: true });
        } catch (e) {
            this.fail(e.name === 'NotReadableError' ? { message: 'تغيّر ملف محلي بعد الفحص. أعد الفحص ثم حاول مجددًا.' } : e);
        }
    },

    cancelLocalDownload(t) {
        if (t.status === 'queued') t.status = 'canceled';
        else downloadControllers.get(t.id)?.abort();
    },
});
