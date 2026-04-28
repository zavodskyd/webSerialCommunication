<x-app-layout>
<div class="min-h-screen bg-slate-50 px-4 py-8 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-5xl space-y-6">
        <header class="space-y-2">
            <h1 class="text-3xl font-semibold text-slate-900">Serial helper — diagnostika</h1>
            <p class="text-sm text-slate-500">Stránka pre Dušana — pomoc pri ladení helper procesu na Win build-och.</p>
            <div class="flex gap-2 text-sm">
                <a href="{{ route('votings.index') }}" class="text-sky-700 hover:underline">← Späť na hlasovania</a>
                <span class="text-slate-300">|</span>
                <a href="{{ url()->current() }}" class="text-sky-700 hover:underline">Obnoviť</a>
            </div>
        </header>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">Konfigurácia</h2>
            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 text-sm">
                <dt class="font-semibold text-slate-700">SERIAL_DRIVER</dt>
                <dd class="font-mono text-slate-900">{{ $driver ?? '(null)' }}</dd>

                <dt class="font-semibold text-slate-700">base_path()</dt>
                <dd class="font-mono break-all text-slate-900">{{ $basePath }}</dd>

                <dt class="font-semibold text-slate-700">storage_path()</dt>
                <dd class="font-mono break-all text-slate-900">{{ $storagePath }}</dd>

                <dt class="font-semibold text-slate-700">app.url</dt>
                <dd class="font-mono text-slate-900">{{ $appUrl ?? '(null)' }}</dd>

                <dt class="font-semibold text-slate-700">logging.default</dt>
                <dd class="font-mono text-slate-900">{{ $logChannel }}</dd>
            </dl>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">Helper script — kandidáti</h2>
            <table class="mt-4 w-full text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="pb-2">Cesta</th>
                        <th class="pb-2">Existuje?</th>
                        <th class="pb-2 text-right">Veľkosť</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($candidates as $c)
                        <tr>
                            <td class="py-2 font-mono break-all text-slate-700">{{ $c['path'] }}</td>
                            <td class="py-2">
                                @if ($c['exists'])
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">áno</span>
                                @else
                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800">nie</span>
                                @endif
                            </td>
                            <td class="py-2 text-right font-mono text-slate-700">{{ $c['size'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Token</h2>
                <p class="mt-2 font-mono text-xs break-all text-slate-600">{{ $tokenInfo['path'] }}</p>
                <p class="mt-2 text-sm">existuje: <strong>{{ $tokenInfo['exists'] ? 'áno' : 'nie' }}</strong>, veľkosť: {{ $tokenInfo['size'] ?? '—' }} B</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Port file</h2>
                <p class="mt-2 font-mono text-xs break-all text-slate-600">{{ $portInfo['path'] }}</p>
                <p class="mt-2 text-sm">existuje: <strong>{{ $portInfo['exists'] ? 'áno' : 'nie' }}</strong>, hodnota: <span class="font-mono">{{ $portInfo['value'] ?? 'null' }}</span></p>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">/health probe</h2>
            <pre class="mt-4 max-h-64 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100">{{ json_encode($health, JSON_PRETTY_PRINT) }}</pre>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">list_ports probe</h2>
            <pre class="mt-4 max-h-64 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100">{{ json_encode($listPorts, JSON_PRETTY_PRINT) }}</pre>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">Diagnostické udalosti (najnovšie hore)</h2>
            <p class="mt-1 text-xs text-slate-500">In-memory ring buffer (max 200 záznamov, 7-dňová expirácia). Nezávisí od laravel.log súboru.</p>
            @if (empty($diagnostics))
                <p class="mt-4 text-sm text-slate-500">Zatiaľ žiadne záznamy.</p>
            @else
                <div class="mt-4 max-h-96 overflow-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Čas</th>
                                <th class="px-3 py-2">Úroveň</th>
                                <th class="px-3 py-2">Správa</th>
                                <th class="px-3 py-2">Kontext</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($diagnostics as $event)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-slate-500">{{ $event['ts'] ?? '' }}</td>
                                    <td class="px-3 py-2">
                                        @php $level = $event['level'] ?? 'info'; @endphp
                                        @if ($level === 'error')
                                            <span class="rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800">{{ $level }}</span>
                                        @else
                                            <span class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-800">{{ $level }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 font-medium text-slate-900">{{ $event['message'] ?? '' }}</td>
                                    <td class="px-3 py-2 font-mono text-slate-600">{{ json_encode($event['context'] ?? [], JSON_UNESCAPED_SLASHES) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">storage/logs/serial-helper-spawn.log (Electron spawn debug)</h2>
            <p class="mt-1 text-xs font-mono break-all text-slate-500">{{ $spawnLogInfo['path'] }}</p>
            <p class="mt-1 text-xs text-slate-500">existuje: <strong>{{ $spawnLogInfo['exists'] ? 'áno' : 'nie' }}</strong>, veľkosť: {{ $spawnLogInfo['size'] ?? '—' }} B</p>
            <p class="mt-1 text-xs text-slate-500">Toto je log z Electron main procesu, ktorý spúšťa helper. Ak helper nebezi, odpoveď je tu.</p>
            @if ($spawnLogInfo['tail'])
                <pre class="mt-3 max-h-96 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100">{{ $spawnLogInfo['tail'] }}</pre>
            @endif
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">storage/logs/serial-helper.log (helper vlastné logy)</h2>
            <p class="mt-1 text-xs font-mono break-all text-slate-500">{{ $helperLogInfo['path'] }}</p>
            <p class="mt-1 text-xs text-slate-500">existuje: <strong>{{ $helperLogInfo['exists'] ? 'áno' : 'nie' }}</strong>, veľkosť: {{ $helperLogInfo['size'] ?? '—' }} B</p>
            @if ($helperLogInfo['tail'])
                <pre class="mt-3 max-h-64 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100">{{ $helperLogInfo['tail'] }}</pre>
            @endif
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-xl font-semibold text-slate-900">storage/logs/laravel.log</h2>
            <p class="mt-1 text-xs font-mono break-all text-slate-500">{{ $laravelLogInfo['path'] }}</p>
            <p class="mt-1 text-xs text-slate-500">existuje: <strong>{{ $laravelLogInfo['exists'] ? 'áno' : 'nie' }}</strong>, veľkosť: {{ $laravelLogInfo['size'] ?? '—' }} B</p>
            @if ($laravelLogInfo['tail'])
                <pre class="mt-3 max-h-96 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100">{{ $laravelLogInfo['tail'] }}</pre>
            @endif
        </section>
    </div>
</div>
</x-app-layout>
