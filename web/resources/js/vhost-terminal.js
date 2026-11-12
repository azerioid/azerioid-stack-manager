// A106: the terminal emulator is bundled with the panel — no third-party CDN code runs in
// the admin origin. The page's inline script waits for this module (Vite modules are deferred).
import '@xterm/xterm/css/xterm.css';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';

window.Terminal = Terminal;
window.FitAddon = { FitAddon };
window.dispatchEvent(new Event('az-xterm-ready'));
