<div wire:poll.2s.visible="refreshState">
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-slate-900">Sériová komunikácia</h1>
            <div class="flex items-center gap-2 rounded-full border border-slate-300/70 bg-white/80 px-3 py-1 text-sm text-slate-700 shadow-sm">
                <span class="{{ $serialConnected ? 'bg-green-500' : 'bg-red-500' }} h-3 w-3 rounded-full"></span>
                <span>{{ $serialConnected ? 'Pripojené' : 'Odpojené' }}</span>
            </div>
            <div class="flex items-center gap-2 rounded-full border border-slate-300/70 bg-white/80 px-3 py-1 text-sm text-slate-700 shadow-sm">
                <span class="{{ $collecting ? 'bg-emerald-500' : 'bg-slate-300' }} h-3 w-3 rounded-full"></span>
                <span>{{ $collecting ? 'Zber beží' : 'Zber stojí' }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-sky-200/80 bg-sky-50/80 p-4 text-sm text-sky-950 shadow-sm">
            <p class="font-medium">Táto stránka číta dáta zo `serial-agent` bridge.</p>
            <p class="mt-1 text-sky-900/80">Port vyber a pripoj v okne Serial Agent. Browser už nepoužíva Web Serial API ani lookup tabuľku kódov.</p>
        </div>

        @if ($agentHealthy === false)
            <div class="rounded-xl border border-rose-200/80 bg-rose-50/80 p-4 text-sm text-rose-900 shadow-sm">
                Serial Agent nie je dostupný{{ $agentError ? ': '.$agentError : '.' }}
            </div>
        @endif

        @if ($statusMessage)
            <div class="rounded-xl border border-slate-200/80 bg-white/90 p-4 text-sm text-slate-800 shadow-sm">
                {{ $statusMessage }}
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <x-primary-button wire:click="refreshState">Aktualizovať stav</x-primary-button>
            <x-secondary-button
                wire:click="startCollection"
                wire:loading.attr="disabled"
                wire:target="startCollection,refreshState"
                class="{{ $collecting ? '!border-green-600 !bg-green-500 !text-white shadow-sm shadow-green-500/30' : '' }}"
            >
                Spustiť zber
            </x-secondary-button>
            <x-secondary-button wire:click="stopCollection" wire:loading.attr="disabled" wire:target="stopCollection,refreshState">
                Zastaviť zber
            </x-secondary-button>
            <x-secondary-button wire:click="disconnectAgent" wire:loading.attr="disabled" wire:target="disconnectAgent,refreshState">
                Odpojiť agent
            </x-secondary-button>
            <x-secondary-button wire:click="clearReceivedData" wire:loading.attr="disabled" wire:target="clearReceivedData">
                Clear
            </x-secondary-button>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-300/70 bg-white/90 p-4 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Port</p>
                <p class="mt-2 font-mono text-sm text-slate-900">{{ $connectedPortPath ?: '-' }}</p>
            </div>
            <div class="rounded-xl border border-slate-300/70 bg-white/90 p-4 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Queued frames</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $queuedFrames }}</p>
            </div>
            <div class="rounded-xl border border-slate-300/70 bg-white/90 p-4 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Prijaté framy</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totalFrames }}</p>
            </div>
            <div class="rounded-xl border border-slate-300/70 bg-white/90 p-4 shadow-sm">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Dekódované / chybné</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $decodedFrames }} / {{ $invalidFrames }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-300/70 bg-white/80 shadow-sm">
            <div class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/95 px-3 py-2 backdrop-blur">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-semibold">Posledný frame:</span>
                    <span class="rounded-full border border-slate-300/70 px-2.5 py-1 font-mono">{{ $activeCode ?: '-' }}</span>
                    <span class="text-slate-600">Zariadenie: {{ $lastMatchedDeviceNumber ?: '-' }}</span>
                    <span class="text-slate-600">Tlačidlo: {{ $lastButtonName ?: '-' }}</span>
                </div>
            </div>

            <div class="max-h-[60vh] overflow-auto">
                <table class="min-w-full divide-y divide-slate-200/80 text-xs">
                    <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur">
                        <tr class="text-left uppercase tracking-[0.18em] text-slate-500">
                            <th class="px-3 py-2 font-semibold">Číslo zariadenia</th>
                            <th class="px-2 py-2 font-semibold">A</th>
                            <th class="px-2 py-2 font-semibold">B</th>
                            <th class="px-2 py-2 font-semibold">C</th>
                            <th class="px-2 py-2 font-semibold">D</th>
                            <th class="px-2 py-2 font-semibold">E</th>
                            <th class="px-2 py-2 font-semibold">F</th>
                            <th class="px-2 py-2 font-semibold">Ruka</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200/70 bg-white/60">
                        @forelse ($visibleDeviceRows as $row)
                            @php($counts = $deviceButtonCounts[$row['normalizedNumber']] ?? [])
                            <tr wire:key="device-row-{{ $row['normalizedNumber'] }}" class="text-slate-700">
                                <td class="px-3 py-2 font-mono text-[11px] font-semibold">{{ $row['displayNumber'] }}</td>
                                @foreach (['A', 'B', 'C', 'D', 'E', 'F', 'Ruka'] as $button)
                                    <td class="px-2 py-1.5">
                                        <div class="flex justify-center">
                                            <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-full border border-slate-300/70 bg-slate-50 px-2 text-[10px] font-semibold text-slate-700">
                                                {{ ($counts[$button] ?? 0) > 0 ? $counts[$button] : '' }}
                                            </span>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-6 text-center text-sm text-slate-500">
                                    Zatiaľ neprišli žiadne dekódované framy.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-300/70 bg-white/80 shadow-sm">
            <div class="border-b border-slate-200/80 bg-slate-50/80 px-3 py-2">
                <h3 class="text-sm font-semibold text-slate-900">Posledné prijaté framy</h3>
            </div>
            <div class="max-h-72 overflow-auto">
                <table class="min-w-full divide-y divide-slate-200/80 text-xs">
                    <thead class="bg-slate-50/95">
                        <tr class="text-left uppercase tracking-[0.18em] text-slate-500">
                            <th class="px-3 py-2 font-semibold">Čas</th>
                            <th class="px-3 py-2 font-semibold">HEX</th>
                            <th class="px-3 py-2 font-semibold">Zariadenie</th>
                            <th class="px-3 py-2 font-semibold">Tlačidlo</th>
                            <th class="px-3 py-2 font-semibold">Stav</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200/70 bg-white/60">
                        @forelse ($recentFrames as $frame)
                            <tr class="text-slate-700">
                                <td class="px-3 py-2 font-mono text-[11px]">{{ $frame['receivedAt'] }}</td>
                                <td class="px-3 py-2 font-mono text-[11px]">{{ $frame['hex'] }}</td>
                                <td class="px-3 py-2 font-mono text-[11px]">{{ $frame['deviceNumber'] ?: '-' }}</td>
                                <td class="px-3 py-2">{{ $frame['buttonName'] ?: '-' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full border px-2 py-1 text-[10px] font-semibold {{ $frame['valid'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' }}">
                                        {{ $frame['valid'] ? 'Dekódovaný' : 'Neplatný' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">
                                    Zatiaľ neprišli žiadne framy.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
