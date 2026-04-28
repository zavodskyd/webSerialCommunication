{{-- Operator console — node-helper driver path. ----------------------------

     Active when config('serial.driver') === 'node-helper'. Replaces the
     legacy Web Serial / @script-block view at voting-console.blade.php.

     Key differences from the legacy view:
       - No JavaScript at all (no Web Serial, no @script, no DOM mutation).
       - Serial port is owned by the Node helper running in the Electron main
         process. UI calls into VotingConsole Livewire methods which proxy to
         the helper via /internal/serial-control.
       - Live updates use wire:poll.500ms. The poll re-renders only the live
         section (results panel + last-vote indicator + timer) — the timer
         is computed server-side from $currentQuestion->opened_at.
       - Buttons use wire:click directly. No `data-*` attributes hand-wired
         to inline JS.

     See docs/design-intent.md for why this exists.
---------------------------------------------------------------------------- --}}

<div data-voting-console class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc,_#eff6ff_55%,_#ffffff)]">
    <div class="w-full space-y-8 px-4 py-4 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('votings.edit', $voting) }}" class="text-sm font-semibold text-sky-700 hover:text-sky-900">
                    ← Späť do editora
                </a>
                <h1 class="mt-3 text-4xl font-semibold tracking-tight text-slate-900">{{ $voting->name }}</h1>
                <p class="mt-2 text-base text-slate-500">{{ $voting->title ?: 'Operátorská konzola hlasovania' }}</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">USB</p>
                    <div class="mt-2 flex items-center gap-3">
                        <span class="h-3 w-3 rounded-full {{ $serialConnected ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                        <span class="text-sm font-semibold text-slate-900">{{ $serialConnected ? 'Pripojené' : 'Nepripojené' }}</span>
                    </div>
                </div>
                <div class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Zber hlasov</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $collectorEnabled ? 'Aktívny' : 'Zastavený' }}</p>
                </div>
                <div class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Odpočet</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $timerRunning ? 'Beží' : 'Stojí' }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-8 xl:grid-cols-[320px,minmax(0,1fr),360px] 2xl:grid-cols-[360px,minmax(0,1fr),400px]">
            <aside class="space-y-6">
                <div class="rounded-[2rem] bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-900">Otázky</h2>
                        <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white">
                            {{ $questions->count() }}
                        </span>
                    </div>

                    <div class="mt-4 space-y-2">
                        @foreach ($questions as $question)
                            <button
                                type="button"
                                wire:key="console-question-{{ $question->id }}"
                                wire:click="selectQuestion({{ $question->id }})"
                                @disabled($collectorEnabled || $resultsVisible)
                                class="block w-full rounded-2xl px-4 py-3 text-left transition {{ $currentQuestion->is($question) ? 'bg-slate-900 text-white' : 'bg-slate-50 text-slate-700 hover:bg-sky-50 hover:text-sky-900' }} disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-semibold">Otázka {{ $question->order }}</span>
                                    <span class="text-xs uppercase tracking-[0.2em] {{ $currentQuestion->is($question) ? 'text-slate-300' : 'text-slate-400' }}">
                                        {{ $question->status }}
                                    </span>
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm {{ $currentQuestion->is($question) ? 'text-slate-300' : 'text-slate-500' }}">
                                    {{ $question->text }}
                                </p>
                            </button>
                        @endforeach
                    </div>
                </div>
            </aside>

            <section class="space-y-6" wire:poll.500ms="liveTick">
                <div class="overflow-hidden rounded-[2.25rem] bg-white shadow-xl shadow-slate-300/30 ring-1 ring-slate-200">
                    <div class="flex flex-col gap-8 px-8 py-8 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-4xl">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-white">
                                    Otázka {{ $currentQuestion->order }}
                                </span>
                                <span class="rounded-full bg-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-slate-600">
                                    {{ $currentQuestion->status }}
                                </span>
                            </div>
                            <p class="mt-6 text-3xl font-medium leading-tight text-slate-900 sm:text-5xl">{{ $currentQuestion->text }}</p>

                            <div class="mt-10 space-y-5">
                                @foreach ($currentQuestion->options as $option)
                                    <div class="flex items-center gap-5 text-2xl font-medium text-slate-900 sm:text-4xl">
                                        <span class="w-16 text-right">{{ $option->key }}.</span>
                                        <span>{{ $option->label }}</span>
                                        <span class="inline-flex h-8 w-14 rounded-md sm:h-10 sm:w-20" style="background-color: {{ $option->color }}"></span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="min-w-[220px] rounded-[2rem] bg-slate-950 px-6 py-8 text-center text-white">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Čas</p>
                            <p class="mt-4 text-6xl font-semibold tracking-tight">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</p>
                            <p class="mt-3 text-sm text-slate-400">Predvolený čas {{ $currentQuestion->response_time_seconds }} s</p>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 bg-slate-50 px-8 py-6">
                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="goToPreviousQuestion" @disabled($collectorEnabled || $resultsVisible) class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60">
                                Predchádzajúca
                            </button>
                            <button type="button" wire:click="startQuestionViaHelper" @disabled(! $serialConnected || $timerRunning) class="rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-60">
                                Start
                            </button>
                            <button type="button" wire:click="pauseQuestionViaHelper" @disabled(! $serialConnected || ! $timerRunning) class="rounded-2xl bg-amber-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-60">
                                Pauza
                            </button>
                            <button type="button" wire:click="finishQuestionViaHelper" @disabled(! $serialConnected || ! $collectorEnabled) class="rounded-2xl bg-rose-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60">
                                Ukončiť otázku
                            </button>
                            <button type="button" wire:click="showResults" @disabled($collectorEnabled || $timerRunning || $resultsVisible) class="rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-60">
                                Zobraziť výsledok
                            </button>
                            <button type="button" wire:click="goToNextQuestion" @disabled($collectorEnabled || $resultsVisible) class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60">
                                Ďalšia
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="text-xl font-semibold text-slate-900">Priebežný výsledok</h2>
                        <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white">
                            vážené hlasy
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">
                        @foreach ($results as $result)
                            <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-4">
                                        <span class="inline-flex h-5 w-5 rounded-full" style="background-color: {{ $result['color'] }}"></span>
                                        <div>
                                            <p class="text-lg font-semibold text-slate-900">{{ $result['key'] }}. {{ $result['label'] }}</p>
                                            <p class="text-sm text-slate-500">{{ $result['vote_count'] }} zariadení</p>
                                        </div>
                                    </div>
                                    <p class="text-3xl font-semibold text-slate-900">{{ rtrim(rtrim(number_format($result['weighted_total'], 2, '.', ' '), '0'), '.') }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <aside class="space-y-6">
                <div class="rounded-[2rem] bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold text-slate-900">USB ovládanie</h2>

                    @if (! $serialConnected)
                        <div class="mt-4 space-y-3">
                            <select wire:model.live="selectedPortPath" class="w-full rounded-2xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="">Vyberte port…</option>
                                @foreach ($availablePorts as $port)
                                    <option value="{{ $port['path'] }}">
                                        {{ $port['path'] }}@if (! empty($port['manufacturer'])) — {{ $port['manufacturer'] }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="flex flex-wrap gap-3">
                                <button type="button" wire:click="refreshSerialPorts" class="rounded-2xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">
                                    Obnoviť zoznam
                                </button>
                                <button type="button" wire:click="connectSerial" @disabled(empty($selectedPortPath)) class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60">
                                    Pripojiť
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 space-y-3">
                            <p class="text-sm text-slate-600">Pripojený port: <span class="font-mono text-slate-900">{{ $connectedPortPath }}</span></p>
                            <button type="button" wire:click="disconnectSerial" @disabled($collectorEnabled) class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60">
                                Odpojiť
                            </button>
                        </div>
                    @endif

                    <p class="mt-3 text-sm text-slate-500">Collector sa pri štarte otázky zapne automaticky. Počas `Pauza` ostáva aktívny.</p>
                </div>

                <div class="rounded-[2rem] bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold text-slate-900">Prezentácia</h2>
                    <p class="mt-2 text-sm text-slate-500">Otvorí samostatné okno pre plátno alebo TV.</p>
                    <a href="{{ route('votings.presentation', $voting) }}" target="_blank" rel="noopener" class="mt-4 inline-flex w-full items-center justify-center rounded-2xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-sky-700">
                        Otvoriť prezentačné okno
                    </a>
                </div>

                <div class="rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-xl font-semibold text-slate-900">Posledný prijatý hlas</h2>
                    <div class="mt-6 rounded-[1.5rem] bg-slate-950 px-6 py-6 text-white">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">Zariadenie</p>
                        <p class="mt-3 text-4xl font-semibold">{{ $lastMatchedDeviceNumber ?: '—' }}</p>
                        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">Voľba</p>
                        <p class="mt-3 text-4xl font-semibold">{{ $lastButtonName ?: '—' }}</p>
                    </div>

                    <div class="mt-6 rounded-[1.5rem] border border-slate-200 bg-slate-50 px-5 py-4">
                        <p class="text-sm font-medium text-slate-500">Stav zápisu</p>
                        <p class="mt-2 text-base font-semibold text-slate-900">{{ $lastVoteMessage ?: 'Zatiaľ neprišiel žiadny hlas.' }}</p>
                    </div>
                </div>
            </aside>
        </div>

        <p class="mt-6 text-center text-xs font-mono text-slate-400">
            build {{ \App\Support\BuildVersion::current() }} (driver: node-helper)
        </p>
    </div>

    @if ($resultsVisible)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4"
            x-data
            @keydown.escape.window="$wire.closeResultsAndAdvance()"
            @click.self="$wire.closeResultsAndAdvance()"
        >
            <div class="w-full max-w-4xl rounded-[2rem] bg-white p-8 shadow-2xl">
                <div class="flex items-start justify-between gap-6">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Výsledok otázky {{ $currentQuestion->order }}</p>
                        <h2 class="mt-3 text-3xl font-semibold text-slate-900">{{ $currentQuestion->text }}</h2>
                    </div>
                    <button type="button" wire:click="closeResultsAndAdvance" class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                        Zavrieť výsledok
                    </button>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-3">
                    @foreach ($results as $result)
                        <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-4 w-4 rounded-full" style="background-color: {{ $result['color'] }}"></span>
                                <p class="text-lg font-semibold text-slate-900">{{ $result['key'] }}. {{ $result['label'] }}</p>
                            </div>
                            <p class="mt-5 text-5xl font-semibold text-slate-900">{{ rtrim(rtrim(number_format($result['weighted_total'], 2, '.', ' '), '0'), '.') }}</p>
                            <p class="mt-2 text-sm text-slate-500">{{ $result['vote_count'] }} zariadení</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
