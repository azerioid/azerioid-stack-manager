import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection } from '@codemirror/view';
import { EditorState, Compartment } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { indentOnInput, bracketMatching, foldGutter, syntaxHighlighting, HighlightStyle, StreamLanguage } from '@codemirror/language';
import { searchKeymap, highlightSelectionMatches, search } from '@codemirror/search';
import { closeBrackets, autocompletion, closeBracketsKeymap, completionKeymap } from '@codemirror/autocomplete';
import { php } from '@codemirror/lang-php';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { xml } from '@codemirror/lang-xml';
import { yaml } from '@codemirror/lang-yaml';
import { properties } from '@codemirror/legacy-modes/mode/properties';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { tags } from '@lezer/highlight';

const language = new Compartment();

const inkTheme = EditorView.theme({
    '&': {
        backgroundColor: '#0b0d11',
        color: '#e4e4e7',
        height: '100%',
    },
    '.cm-content': { caretColor: '#e0b36a' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: '#e0b36a' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'rgba(201, 150, 61, 0.28)',
    },
    '.cm-activeLine': { backgroundColor: '#171b22' },
    '.cm-activeLineGutter': { backgroundColor: '#171b22' },
    '.cm-gutters': {
        backgroundColor: '#11141a',
        color: '#71717a',
        borderRight: '1px solid rgba(255,255,255,0.06)',
    },
    '.cm-foldPlaceholder': {
        backgroundColor: '#1e242d',
        border: 'none',
        color: '#a1a1aa',
    },
    '.cm-tooltip': {
        backgroundColor: '#171b22',
        border: '1px solid rgba(255,255,255,0.08)',
        color: '#e4e4e7',
    },
    '.cm-searchMatch': { backgroundColor: 'rgba(212, 160, 84, 0.35)' },
    '.cm-searchMatch.cm-searchMatch-selected': { backgroundColor: 'rgba(201, 150, 61, 0.55)' },
    '.cm-panels': { backgroundColor: '#11141a', color: '#e4e4e7' },
    '.cm-panels .cm-button': {
        background: '#1e242d',
        color: '#e4e4e7',
        border: '1px solid rgba(255,255,255,0.1)',
    },
    '.cm-textfield': {
        background: '#0b0d11',
        border: '1px solid rgba(255,255,255,0.1)',
        color: '#e4e4e7',
    },
    '.cm-panel.cm-search label': { color: '#a1a1aa' },
}, { dark: true });

const inkHighlight = HighlightStyle.define([
    { tag: tags.keyword, color: '#e0b36a' },
    { tag: tags.operator, color: '#d4a054' },
    { tag: tags.string, color: '#3d9a7a' },
    { tag: tags.number, color: '#d4a054' },
    { tag: tags.comment, color: '#71717a', fontStyle: 'italic' },
    { tag: tags.variableName, color: '#e4e4e7' },
    { tag: [tags.function(tags.variableName), tags.definition(tags.variableName)], color: '#7eb8c9' },
    { tag: tags.propertyName, color: '#c4b5a0' },
    { tag: tags.tagName, color: '#e0b36a' },
    { tag: tags.attributeName, color: '#7eb8c9' },
    { tag: tags.typeName, color: '#c9963d' },
    { tag: tags.bool, color: '#c45c4a' },
    { tag: tags.null, color: '#c45c4a' },
    { tag: tags.heading, color: '#e0b36a', fontWeight: '600' },
    { tag: tags.link, color: '#7eb8c9' },
    { tag: tags.meta, color: '#a1a1aa' },
]);

function languageFor(path) {
    const name = (path || '').split('/').pop() || '';
    const lower = name.toLowerCase();
    if (lower === '.env' || lower.startsWith('.env.') || lower.endsWith('.ini') || lower.endsWith('.conf')) {
        return StreamLanguage.define(properties);
    }
    const ext = lower.includes('.') ? lower.split('.').pop() : '';
    switch (ext) {
        case 'php':
        case 'phtml':
            return php();
        case 'html':
        case 'htm':
            return html();
        case 'css':
        case 'scss':
        case 'less':
            return css();
        case 'js':
        case 'mjs':
        case 'cjs':
        case 'jsx':
            return javascript();
        case 'ts':
        case 'tsx':
            return javascript({ typescript: true });
        case 'json':
            return json();
        case 'md':
        case 'markdown':
            return markdown();
        case 'xml':
        case 'svg':
            return xml();
        case 'yml':
        case 'yaml':
            return yaml();
        case 'sh':
        case 'bash':
            return StreamLanguage.define(shell);
        default:
            return [];
    }
}

let view = null;
let savedDoc = '';

function destroy() {
    if (view) {
        view.destroy();
        view = null;
    }
}

function mount(host, opts) {
    destroy();
    const doc = opts.doc ?? '';
    savedDoc = doc;
    view = new EditorView({
        state: EditorState.create({
            doc,
            extensions: [
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightActiveLine(),
                foldGutter(),
                drawSelection(),
                history(),
                indentOnInput(),
                bracketMatching(),
                closeBrackets(),
                autocompletion(),
                search(),
                highlightSelectionMatches(),
                language.of(languageFor(opts.path || '')),
                inkTheme,
                syntaxHighlighting(inkHighlight),
                keymap.of([
                    {
                        key: 'Mod-s',
                        preventDefault: true,
                        run: () => {
                            const text = view.state.doc.toString();
                            opts.onSave?.(text);
                            return true;
                        },
                    },
                    ...closeBracketsKeymap,
                    ...completionKeymap,
                    ...searchKeymap,
                    ...historyKeymap,
                    ...defaultKeymap,
                    indentWithTab,
                ]),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        const text = update.state.doc.toString();
                        opts.onChange?.(text, text !== savedDoc);
                    }
                }),
                EditorView.lineWrapping,
            ],
        }),
        parent: host,
    });
    queueMicrotask(() => view?.focus());
    return view;
}

function getDoc() {
    return view ? view.state.doc.toString() : null;
}

function insert(text) {
    if (!view) {
        return;
    }
    const pos = view.state.selection.main.head;
    view.dispatch({
        changes: { from: pos, insert: text },
        selection: { anchor: pos + String(text).length },
    });
}

function markClean(doc) {
    savedDoc = typeof doc === 'string' ? doc : getDoc();
}

window.azVhostEditor = { mount, destroy, getDoc, markClean, insert };
window.dispatchEvent(new Event('az-vhost-editor-ready'));
