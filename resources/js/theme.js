// Dark / light theme stored per browser (non-sensitive convenience only).
export function currentTheme() {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

export function toggleTheme() {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.documentElement.classList.toggle('dark', next === 'dark');
    try {
        localStorage.setItem('sftp.theme', next);
    } catch {}
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: next }));
    return next;
}
