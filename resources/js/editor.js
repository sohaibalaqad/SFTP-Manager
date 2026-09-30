// CodeMirror 6 editor, loaded on demand (code-split) when a text file is opened.
import { basicSetup } from 'codemirror';
import { EditorState, Compartment } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { StreamLanguage } from '@codemirror/language';
import { oneDark } from '@codemirror/theme-one-dark';
import { javascript } from '@codemirror/lang-javascript';
import { php } from '@codemirror/lang-php';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { json } from '@codemirror/lang-json';
import { xml } from '@codemirror/lang-xml';
import { yaml } from '@codemirror/lang-yaml';
import { markdown } from '@codemirror/lang-markdown';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { properties } from '@codemirror/legacy-modes/mode/properties';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { python } from '@codemirror/legacy-modes/mode/python';
import { standardSQL } from '@codemirror/legacy-modes/mode/sql';
import { dockerFile } from '@codemirror/legacy-modes/mode/dockerfile';

function languageFor(filename) {
    const name = filename.toLowerCase();
    const ext = name.includes('.') ? name.split('.').pop() : '';

    if (name.endsWith('.blade.php')) return html();
    if (name === 'dockerfile') return StreamLanguage.define(dockerFile);
    if (name.startsWith('.env') || name === '.htaccess' || name === '.editorconfig' || name === '.gitignore') {
        return StreamLanguage.define(properties);
    }

    switch (ext) {
        case 'php':
        case 'phtml':
            return php();
        case 'js':
        case 'mjs':
        case 'cjs':
            return javascript();
        case 'jsx':
            return javascript({ jsx: true });
        case 'ts':
            return javascript({ typescript: true });
        case 'tsx':
            return javascript({ jsx: true, typescript: true });
        case 'css':
        case 'scss':
        case 'less':
            return css();
        case 'html':
        case 'htm':
        case 'vue':
            return html();
        case 'json':
        case 'lock':
            return json();
        case 'xml':
        case 'svg':
        case 'xsd':
        case 'plist':
            return xml();
        case 'yml':
        case 'yaml':
            return yaml();
        case 'md':
        case 'markdown':
            return markdown();
        case 'conf':
        case 'nginx':
            return StreamLanguage.define(nginx);
        case 'env':
        case 'ini':
        case 'properties':
        case 'cfg':
        case 'toml':
            return StreamLanguage.define(properties);
        case 'sh':
        case 'bash':
        case 'zsh':
            return StreamLanguage.define(shell);
        case 'py':
            return StreamLanguage.define(python);
        case 'sql':
            return StreamLanguage.define(standardSQL);
        default:
            return [];
    }
}

const lightTheme = EditorView.theme({
    '&': { backgroundColor: '#fff' },
    '.cm-gutters': { backgroundColor: '#f8fafc', borderRight: '1px solid #e2e8f0', color: '#94a3b8' },
});

/**
 * @returns {{ getValue(): string, setTheme(dark: boolean): void, focus(): void, destroy(): void }}
 */
export function createEditor(parent, { content, filename, dark, onChange, onSave }) {
    const theme = new Compartment();
    const extensions = [
        basicSetup,
        keymap.of([
            indentWithTab,
            { key: 'Mod-s', preventDefault: true, run: () => (onSave(), true) },
        ]),
        languageFor(filename),
        theme.of(dark ? oneDark : lightTheme),
        EditorView.updateListener.of((u) => u.docChanged && onChange()),
    ];

    // Preserve Windows line endings when the file uses them.
    if (content.includes('\r\n')) extensions.push(EditorState.lineSeparator.of('\r\n'));

    const view = new EditorView({ parent, state: EditorState.create({ doc: content, extensions }) });

    return {
        getValue: () => view.state.doc.toString(),
        setTheme: (isDark) => view.dispatch({ effects: theme.reconfigure(isDark ? oneDark : lightTheme) }),
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
}
