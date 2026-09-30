// Small helpers shared by the file manager and the local panel.

export const randomId = () => Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
export const hexId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');

// Wrap a file name so it renders correctly (LTR) inside Arabic sentences.
export const iso = (s) => `⁨${s}⁩`;

export const joinRel = (dir, name) => (dir && name ? `${dir}/${name}` : dir || name);
export const parentRel = (rel) => (rel.includes('/') ? rel.slice(0, rel.lastIndexOf('/')) : '');

export function formatSize(bytes) {
    if (bytes === null || bytes === undefined) return '';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let v = bytes / 1024;
    let u = 0;
    while (v >= 1024 && u < units.length - 1) {
        v /= 1024;
        u++;
    }
    return `${v < 10 ? v.toFixed(1) : Math.round(v)} ${units[u]}`;
}

export function formatDate(ts) {
    if (!ts) return '';
    const d = new Date(ts * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/**
 * What is being dragged: OS files, rows from the local panel, or rows from the server list.
 * Internal drags carry a custom type; their items are kept in `drag.payload` (not in DataTransfer).
 */
export const LOCAL_TYPE = 'application/x-sftp-local';
export const REMOTE_TYPE = 'application/x-sftp-remote';
export const drag = { payload: null };

export function dragKind(e) {
    const types = [...(e.dataTransfer?.types ?? [])];
    if (types.includes(LOCAL_TYPE)) return 'local';
    if (types.includes(REMOTE_TYPE)) return 'remote';
    if (types.includes('Files')) return 'files';
    return null;
}
