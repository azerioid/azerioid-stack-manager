<div class="space-y-4">
    @if ($error)
        <div class="rounded-md border border-bad/40 bg-bad/10 px-4 py-3 text-sm text-bad">{{ $error }}</div>
        <a href="/vhosts" class="btn-ghost inline-block">Back to vhosts</a>
    @elseif ($sessionId && $wsPath)
        <div class="panel p-4 space-y-3" id="vhost-terminal-panel"
             data-session-id="{{ $sessionId }}"
             data-ws-path="{{ $wsPath }}"
             data-idle-seconds="{{ $idleSeconds }}">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-zinc-400">
                <div>
                    <span class="font-mono text-zinc-200">{{ $domain }}</span>
                    · user <span class="font-mono text-brass-400">{{ $username }}</span>
                    · cwd <span class="font-mono text-zinc-300">{{ $root }}</span>
                </div>
                <div class="flex gap-2 items-center">
                    <span class="text-xs">Ends after {{ (int) max(1, (int) ($idleSeconds / 60)) }} min idle</span>
                    <button type="button" class="btn-ghost text-xs" wire:click="stop">Close session</button>
                </div>
            </div>
            <div id="terminal-host" class="h-[min(70vh,560px)] w-full overflow-hidden rounded border border-white/10 bg-black"></div>
        </div>
        {{-- A106: xterm is bundled (resources/js/vhost-terminal.js), not loaded from a CDN. --}}
        @vite('resources/js/vhost-terminal.js')
        <script>
        (function init() {
            const panel = document.getElementById('vhost-terminal-panel');
            if (!panel || panel.dataset.initialized === '1') return;
            if (!window.Terminal) {
                window.addEventListener('az-xterm-ready', init, { once: true });
                return;
            }
            panel.dataset.initialized = '1';

            const sessionId = panel.dataset.sessionId;
            const wsPath = panel.dataset.wsPath;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const encoder = new TextEncoder();
            const decoder = new TextDecoder();

            const Command = { OUTPUT: '0', INPUT: '0', RESIZE: '1' };

            const term = new Terminal({
                cursorBlink: true,
                fontFamily: 'ui-monospace, Menlo, Monaco, Consolas, monospace',
                fontSize: 13,
                theme: { background: '#0a0a0a', foreground: '#e4e4e7' },
            });
            const FitAddonCtor = window.FitAddon?.FitAddon ?? window.FitAddon;
            const fit = FitAddonCtor ? new FitAddonCtor() : null;
            if (fit) term.loadAddon(fit);
            term.open(document.getElementById('terminal-host'));
            fit?.fit();
            window.addEventListener('resize', () => fit?.fit());

            let socket = null;
            let stopping = false;
            let closedShown = false;

            const sendInput = (data) => {
                if (!socket || socket.readyState !== WebSocket.OPEN) return;
                const payload = typeof data === 'string'
                    ? encoder.encode(data)
                    : data;
                const frame = new Uint8Array(payload.length + 1);
                frame[0] = Command.INPUT.charCodeAt(0);
                frame.set(payload, 1);
                socket.send(frame);
            };

            const sendResize = () => {
                if (!socket || socket.readyState !== WebSocket.OPEN) return;
                const msg = JSON.stringify({ columns: term.cols, rows: term.rows });
                socket.send(encoder.encode(Command.RESIZE + msg));
            };

            const connect = async () => {
                let token = '';
                try {
                    const resp = await fetch(`${wsPath}/token`, { credentials: 'same-origin' });
                    if (resp.ok) {
                        const json = await resp.json();
                        token = json.token ?? '';
                    }
                } catch (_) {}

                const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
                socket = new WebSocket(`${proto}//${location.host}${wsPath}/ws`, ['tty']);
                socket.binaryType = 'arraybuffer';

                socket.onopen = () => {
                    closedShown = false;
                    const auth = JSON.stringify({
                        AuthToken: token,
                        columns: term.cols,
                        rows: term.rows,
                    });
                    socket.send(encoder.encode(auth));
                    sendResize();
                    term.focus();
                };

                socket.onmessage = (event) => {
                    const raw = event.data;
                    if (!(raw instanceof ArrayBuffer)) return;
                    const bytes = new Uint8Array(raw);
                    if (bytes.length === 0) return;
                    const cmd = String.fromCharCode(bytes[0]);
                    const data = bytes.slice(1);
                    if (cmd === Command.OUTPUT) {
                        term.write(data);
                    }
                };

                term.onData(sendInput);
                term.onResize(() => sendResize());

                socket.onclose = () => {
                    if (stopping || closedShown) return;
                    closedShown = true;
                    term.writeln('\r\n\r\n[session closed]');
                };
            };

            connect().catch(() => {
                term.writeln('\r\n\r\n[session closed]');
            });

            const heartbeat = setInterval(() => {
                fetch(`/terminal/heartbeat/${sessionId}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    credentials: 'same-origin',
                }).catch(() => {});
            }, 60000);

            const stop = () => {
                if (stopping) return;
                stopping = true;
                clearInterval(heartbeat);
                try { socket?.close(1000); } catch (_) {}
                const body = new URLSearchParams({ _token: csrf });
                navigator.sendBeacon(`/terminal/stop/${sessionId}`, body);
            };

            window.addEventListener('beforeunload', stop);
        })();
        </script>
    @endif
</div>
