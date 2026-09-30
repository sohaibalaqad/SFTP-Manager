// Small fetch wrapper: JSON in/out, CSRF header, friendly error messages.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export class ApiError extends Error {
    constructor(message, status = 0, body = {}) {
        super(message);
        this.status = status;
        this.body = body;
    }
}

export function flash(message) {
    try {
        sessionStorage.setItem('sftp.flash', message);
    } catch {}
}

export function takeFlash() {
    try {
        const m = sessionStorage.getItem('sftp.flash');
        sessionStorage.removeItem('sftp.flash');
        return m;
    } catch {
        return null;
    }
}

export async function api(method, url, data = null, { signal } = {}) {
    const opts = {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
    };

    if (method === 'GET' && data) {
        url += '?' + new URLSearchParams(data);
    } else if (data instanceof FormData) {
        opts.body = data;
    } else if (data) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data);
    }

    let res;
    try {
        res = await fetch(url, opts);
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        throw new ApiError('تعذر الوصول إلى الخادم. تحقق من اتصال الشبكة.');
    }

    let body = {};
    try {
        body = await res.json();
    } catch {}

    if (!res.ok) {
        const err = new ApiError(body.message || 'حدث خطأ غير متوقع.', res.status, body);
        if (body.reconnect) sessionExpired(err.message);
        throw err;
    }

    return body;
}

export function sessionExpired(message) {
    flash(message);
    window.location.href = '/';
}

/**
 * POST FormData with upload progress (fetch has no upload progress events).
 */
export function uploadWithProgress(url, formData, onProgress, onXhr) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded);
        xhr.onload = () => {
            let body = {};
            try {
                body = JSON.parse(xhr.responseText);
            } catch {}
            if (xhr.status >= 200 && xhr.status < 300) return resolve(body);
            if (body.reconnect) sessionExpired(body.message);
            reject(new ApiError(body.message || (xhr.status === 413 ? 'حجم الجزء أكبر من المسموح في إعدادات الخادم.' : 'تعذر رفع الملف.'), xhr.status, body));
        };
        xhr.onerror = () => reject(new ApiError('تعذر رفع الملف. تحقق من اتصال الشبكة.'));
        xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));
        onXhr?.(xhr);
        xhr.send(formData);
    });
}
