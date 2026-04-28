<div
    data-voting-console
    class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc,_#eff6ff_55%,_#ffffff)]"
>
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
                <div wire:ignore class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">USB</p>
                    <div class="mt-2 flex items-center gap-3">
                        <span data-usb-indicator class="h-3 w-3 rounded-full bg-rose-500"></span>
                        <span data-usb-status class="text-sm font-semibold text-slate-900">Nepripojené</span>
                    </div>
                </div>
                <div class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Zber hlasov</p>
                    <p data-collector-status class="mt-2 text-sm font-semibold text-slate-900">{{ $collectorEnabled ? 'Aktívny' : 'Zastavený' }}</p>
                </div>
                <div class="rounded-[1.5rem] bg-white px-5 py-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Odpočet</p>
                    <p data-timer-status class="mt-2 text-sm font-semibold text-slate-900">{{ $timerRunning ? 'Beží' : 'Stojí' }}</p>
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

            <section class="space-y-6">
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
                            <p data-remaining-time class="mt-4 text-6xl font-semibold tracking-tight">{{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}</p>
                            <p class="mt-3 text-sm text-slate-400">Predvolený čas {{ $currentQuestion->response_time_seconds }} s</p>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 bg-slate-50 px-8 py-6">
                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="goToPreviousQuestion" @disabled($collectorEnabled || $resultsVisible) class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60">
                                Predchádzajúca
                            </button>
                            <button type="button" data-start-question disabled class="rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-60">
                                Start
                            </button>
                            <button type="button" wire:click="pauseQuestion" data-pause-question disabled class="rounded-2xl bg-amber-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-60">
                                Pauza
                            </button>
                            <button type="button" data-finish-question disabled class="rounded-2xl bg-rose-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60">
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
                            <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4" data-result-key="{{ $result['key'] }}">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-4">
                                        <span class="inline-flex h-5 w-5 rounded-full" style="background-color: {{ $result['color'] }}"></span>
                                        <div>
                                            <p class="text-lg font-semibold text-slate-900">{{ $result['key'] }}. {{ $result['label'] }}</p>
                                            <p class="text-sm text-slate-500"><span data-result-vote-count>{{ $result['vote_count'] }}</span> zariadení</p>
                                        </div>
                                    </div>
                                    <p data-result-weighted-total class="text-3xl font-semibold text-slate-900">{{ rtrim(rtrim(number_format($result['weighted_total'], 2, '.', ' '), '0'), '.') }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <aside class="space-y-6">
                <div wire:ignore class="rounded-[2rem] bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-lg font-semibold text-slate-900">USB ovládanie</h2>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button type="button" data-usb-connect class="rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60">
                            Pripojiť
                        </button>
                        <button type="button" data-usb-disconnect disabled class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60">
                            Odpojiť
                        </button>
                    </div>
                    <p class="mt-3 text-sm text-slate-500">Collector sa pri štarte otázky zapne automaticky. Počas `Pause` ostáva aktívny.</p>
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
                        <p data-last-device class="mt-3 text-4xl font-semibold">{{ $lastMatchedDeviceNumber ?: '—' }}</p>
                        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">Voľba</p>
                        <p data-last-button class="mt-3 text-4xl font-semibold">{{ $lastButtonName ?: '—' }}</p>
                    </div>

                    <div class="mt-6 rounded-[1.5rem] border border-slate-200 bg-slate-50 px-5 py-4">
                        <p class="text-sm font-medium text-slate-500">Stav zápisu</p>
                        <p data-last-vote-message class="mt-2 text-base font-semibold text-slate-900">{{ $lastVoteMessage ?: 'Zatiaľ neprišiel žiadny hlas.' }}</p>
                    </div>
                </div>
            </aside>
        </div>
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

@script
<script>
(() => {
    const runtimeKey = "__votingConsoleSerialRuntime";
    const initialConsoleState = {
        collectorEnabled: @js($collectorEnabled),
        timerRunning: @js($timerRunning),
        remainingSeconds: @js($remainingSeconds),
        resultsVisible: @js($resultsVisible),
        codeLookup: @js($codeLookup),
        codePrefixes: @js($codePrefixes),
    };

    if (window[runtimeKey]) {
        window[runtimeKey].attach($wire, initialConsoleState);

        return;
    }

    let componentWire = $wire;
    let consoleRoot = componentWire.$el;
    const state = {
        collectorEnabled: initialConsoleState.collectorEnabled,
        timerRunning: initialConsoleState.timerRunning,
        remainingSeconds: initialConsoleState.remainingSeconds,
        resultsVisible: initialConsoleState.resultsVisible,
        codeLookup: initialConsoleState.codeLookup,
        codePrefixes: initialConsoleState.codePrefixes,
        baudRate: 28800,
        dataBits: 8,
        parity: 'none',
        stopBits: 1,
        flowControl: 'none',
        serialPort: null,
        reader: null,
        readerStopPromise: null,
        writer: null,
        isConnected: false,
        isReading: false,
        messageByteLength: 3,
        incomingBytes: [],
        timerId: null,
        finishingTimer: false,
        startingCommunication: false,
        stoppingCommunication: false,
        syncingRemainingSeconds: false,
        disconnecting: false,
        lastSyncedRemainingSeconds: @js($remainingSeconds),
        pendingVoteCodes: [],
        preparingQuestionStart: false,
    };

    const element = (selector) => consoleRoot.querySelector(selector);

    const formatSeconds = (value) => {
        const minutes = String(Math.floor(value / 60)).padStart(2, '0');
        const seconds = String(value % 60).padStart(2, '0');

        return `${minutes}:${seconds}`;
    };

    const formatWeightedTotal = (value) => Number(value)
        .toLocaleString('sk-SK', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });

    const setDisabled = (selector, disabled) => {
        const target = element(selector);

        if (target) {
            target.disabled = disabled;
        }
    };

    const renderConsoleState = () => {
        const indicator = element('[data-usb-indicator]');
        const usbStatus = element('[data-usb-status]');
        const collectorStatus = element('[data-collector-status]');
        const timerStatus = element('[data-timer-status]');
        const remainingTime = element('[data-remaining-time]');

        if (indicator) {
            indicator.classList.toggle('bg-emerald-500', state.isConnected);
            indicator.classList.toggle('bg-rose-500', !state.isConnected);
        }

        if (usbStatus) {
            usbStatus.textContent = state.isConnected ? 'Pripojené' : 'Nepripojené';
        }

        if (collectorStatus) {
            collectorStatus.textContent = state.collectorEnabled
                ? 'Aktívny'
                : (state.preparingQuestionStart ? 'Aktivuje sa' : 'Zastavený');
        }

        if (timerStatus) {
            timerStatus.textContent = state.timerRunning ? 'Beží' : 'Stojí';
        }

        if (remainingTime) {
            remainingTime.textContent = formatSeconds(state.remainingSeconds);
        }

        setDisabled('[data-usb-connect]', state.isConnected);
        setDisabled(
            '[data-usb-disconnect]',
            !state.isConnected || state.collectorEnabled || state.preparingQuestionStart,
        );
        setDisabled('[data-start-question]', !state.isConnected || state.timerRunning || state.preparingQuestionStart);
        setDisabled('[data-pause-question]', !state.isConnected || !state.timerRunning);
        setDisabled('[data-finish-question]', !state.isConnected || !state.collectorEnabled);
    };

    const renderVoteResult = (response) => {
        const lastDevice = element('[data-last-device]');
        const lastButton = element('[data-last-button]');
        const lastVoteMessage = element('[data-last-vote-message]');

        if (lastDevice) {
            lastDevice.textContent = response.lastMatchedDeviceNumber || '—';
        }

        if (lastButton) {
            lastButton.textContent = response.lastButtonName || '—';
        }

        if (lastVoteMessage) {
            lastVoteMessage.textContent = response.message || 'Zatiaľ neprišiel žiadny hlas.';
        }

        for (const result of response.results || []) {
            const resultElement = element(`[data-result-key="${result.key}"]`);

            if (!resultElement) {
                continue;
            }

            const voteCount = resultElement.querySelector('[data-result-vote-count]');
            const weightedTotal = resultElement.querySelector('[data-result-weighted-total]');

            if (voteCount) {
                voteCount.textContent = result.vote_count;
            }

            if (weightedTotal) {
                weightedTotal.textContent = formatWeightedTotal(result.weighted_total);
            }
        }
    };

    const renderPendingVoteCode = (hexData) => {
        const lastDevice = element('[data-last-device]');
        const lastButton = element('[data-last-button]');
        const lastVoteMessage = element('[data-last-vote-message]');
        const resolved = state.codeLookup[hexData] || null;

        if (lastDevice) {
            lastDevice.textContent = resolved?.deviceNumber || '—';
        }

        if (lastButton) {
            lastButton.textContent = resolved?.buttonName || '—';
        }

        if (lastVoteMessage) {
            lastVoteMessage.textContent = resolved
                ? `Prijatý signál ${resolved.deviceNumber} / ${resolved.buttonName}, čaká na aktiváciu hlasovania.`
                : 'Prijatý signál, čaká na aktiváciu hlasovania.';
        }
    };

    const applyConsoleState = async (detail) => {
        const previousCollectorEnabled = state.collectorEnabled;
        const previousTimerRunning = state.timerRunning;

        if (typeof detail.collectorEnabled === 'boolean') {
            state.collectorEnabled = detail.collectorEnabled;
        }

        if (typeof detail.timerRunning === 'boolean') {
            state.timerRunning = detail.timerRunning;
        }

        if (typeof detail.remainingSeconds === 'number') {
            state.remainingSeconds = detail.remainingSeconds;
        }

        if (typeof detail.resultsVisible === 'boolean') {
            state.resultsVisible = detail.resultsVisible;
        }

        if (state.isConnected && state.collectorEnabled && !state.isReading) {
            await startCommunication();
        }

        if (state.isConnected && !state.collectorEnabled && state.isReading && !state.preparingQuestionStart) {
            await stopCommunication();
        }

        if (!state.collectorEnabled && !state.preparingQuestionStart) {
            state.pendingVoteCodes = [];
        }

        if (state.collectorEnabled) {
            state.preparingQuestionStart = false;
            await flushPendingVoteCodes();
        }

        if (state.timerRunning && !previousTimerRunning) {
            startTimer();
        }

        if (!state.timerRunning && previousTimerRunning) {
            stopTimer();
        }

        if (previousCollectorEnabled !== state.collectorEnabled) {
            renderConsoleState();
        }

        renderConsoleState();
    };

    const attach = (wire, detail) => {
        componentWire = wire;
        consoleRoot = componentWire.$el;

        if (detail.codeLookup) {
            state.codeLookup = detail.codeLookup;
        }

        if (detail.codePrefixes) {
            state.codePrefixes = detail.codePrefixes;
        }

        applyConsoleState(detail);
        renderConsoleState();
    };

    const startTimer = () => {
        stopTimer();

        state.timerId = setInterval(async () => {
            if (!state.timerRunning) {
                return;
            }

            if (state.remainingSeconds <= 0) {
                if (state.finishingTimer) {
                    return;
                }

                state.finishingTimer = true;
                clearInterval(state.timerId);
                await finishQuestionFromFrontend(0);
                state.finishingTimer = false;

                return;
            }

            state.remainingSeconds -= 1;
            renderConsoleState();
            syncRemainingSeconds();
        }, 1000);
    };

    const stopTimer = () => {
        if (state.timerId) {
            clearInterval(state.timerId);
            state.timerId = null;
        }
    };

    const connect = async () => {
        try {
            state.serialPort = await navigator.serial.requestPort();

            await state.serialPort.open({
                baudRate: state.baudRate,
                dataBits: state.dataBits,
                parity: state.parity,
                stopBits: state.stopBits,
                flowControl: state.flowControl,
            });

            state.isConnected = true;
            state.writer = state.serialPort.writable.getWriter();

            await initializeDevice();

            renderConsoleState();
        } catch (error) {
            console.error('Failed to connect:', error);
        }
    };

    const initializeDevice = async () => {
        await sendHexCommand('f400c00236', 5);
        await sendHexCommand('f500000101f5', 6);
        await sendHexCommand('f54b4e050200000601f0', 10);
    };

    const waitForCommunicationStop = async () => {
        for (let attempt = 0; attempt < 20 && state.stoppingCommunication; attempt += 1) {
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
    };

    const waitForReaderStop = async () => {
        if (!state.readerStopPromise) {
            return;
        }

        await state.readerStopPromise;
    };

    const startQuestionFromFrontend = async () => {
        if (!state.isConnected || state.timerRunning || state.preparingQuestionStart) {
            return;
        }

        state.preparingQuestionStart = true;
        state.pendingVoteCodes = [];
        renderConsoleState();

        try {
            await startCommunication();
            await applyConsoleState(await componentWire.startQuestion());
        } catch (error) {
            state.preparingQuestionStart = false;
            console.error('Failed to start question:', error);
            renderConsoleState();
        }
    };

    const finishQuestionFromFrontend = async (remainingSeconds = state.remainingSeconds) => {
        if (!state.isConnected || !state.collectorEnabled) {
            return;
        }

        stopTimer();

        try {
            await stopCommunication();
            await componentWire.finishQuestion(remainingSeconds);
        } catch (error) {
            console.error('Failed to finish question:', error);
        } finally {
            renderConsoleState();
        }
    };

    const startCommunication = async () => {
        if (state.isReading || state.startingCommunication || !state.serialPort) {
            return;
        }

        await waitForCommunicationStop();
        await waitForReaderStop();

        if (state.isReading || state.stoppingCommunication) {
            return;
        }

        state.startingCommunication = true;
        state.incomingBytes = [];

        try {
            state.isReading = true;
            state.readerStopPromise = readData();

            // Drain any bytes the device emitted between the previous stop and now
            // (button presses while the result modal was open, echoed stop frames, etc.).
            // Without this the 3-byte frame parser realigns one byte at a time and can
            // swallow the first valid press of the next question.
            await delay(150);
            state.incomingBytes = [];

            // Init runs once at connect(), not per question — the device stays
            // initialised for the whole session and only the enable/disable
            // commands (5a80da / 5b80db) toggle the collector between questions.
            await sendHexCommand('5b80db', 3);
            await sendHexCommand('5a80da', 3);
        } finally {
            state.startingCommunication = false;
            renderConsoleState();
        }
    };

    const stopCommunication = async () => {
        if (state.stoppingCommunication) {
            await waitForCommunicationStop();
            await waitForReaderStop();

            return;
        }

        state.stoppingCommunication = true;
        state.isReading = false;

        try {
            await sendHexCommand('5b80db', 3);

            if (state.reader) {
                const reader = state.reader;

                try {
                    await Promise.race([
                        reader.cancel(),
                        new Promise((resolve) => setTimeout(resolve, 1000)),
                    ]);
                } catch (error) {
                    console.log('Reader already cancelled or released');
                }
            }

            await waitForReaderStop();
        } finally {
            state.stoppingCommunication = false;
            renderConsoleState();
        }
    };

    const readData = async () => {
        try {
            while (state.isReading) {
                const reader = state.serialPort.readable.getReader();
                state.reader = reader;

                try {
                    while (state.isReading) {
                        const { value, done } = await reader.read();

                        if (done) {
                            state.isReading = false;
                            break;
                        }

                        queueIncomingBytes(value);
                        await processIncomingMessages();
                    }
                } finally {
                    reader.releaseLock();

                    if (state.reader === reader) {
                        state.reader = null;
                    }
                }
            }
        } catch (error) {
            console.error('Error reading data:', error);
        } finally {
            state.isReading = false;
            state.reader = null;
            state.readerStopPromise = null;
            renderConsoleState();
        }
    };

    const syncRemainingSeconds = async () => {
        if (
            state.syncingRemainingSeconds
            || state.finishingTimer
            || state.remainingSeconds === state.lastSyncedRemainingSeconds
            || state.remainingSeconds % 5 !== 0
        ) {
            return;
        }

        state.syncingRemainingSeconds = true;

        try {
            await componentWire.syncRemainingSeconds(state.remainingSeconds);
            state.lastSyncedRemainingSeconds = state.remainingSeconds;
        } catch (error) {
            console.error('Failed to sync remaining seconds:', error);
        } finally {
            state.syncingRemainingSeconds = false;
        }
    };

    const queueIncomingBytes = (buffer) => {
        state.incomingBytes.push(...Array.from(buffer));
    };

    const processIncomingMessages = async () => {
        while (state.incomingBytes.length >= state.messageByteLength) {
            const frame = state.incomingBytes.slice(0, state.messageByteLength);
            const hexData = byteArrayToHex(frame);

            if (!state.codeLookup[hexData]) {
                resyncIncomingBytes();
                continue;
            }

            state.incomingBytes.splice(0, state.messageByteLength);

            if (!state.collectorEnabled) {
                if (state.preparingQuestionStart) {
                    state.pendingVoteCodes.push(hexData);
                }

                renderPendingVoteCode(hexData);

                continue;
            }

            try {
                await recordVoteCode(hexData);
            } catch (error) {
                console.error('Failed to record vote:', error);
            }
        }
    };

    const flushPendingVoteCodes = async () => {
        while (state.collectorEnabled && state.pendingVoteCodes.length > 0) {
            await recordVoteCode(state.pendingVoteCodes.shift());
        }
    };

    const recordVoteCode = async (hexData) => {
        renderVoteResult(await componentWire.recordVoteFromCode(hexData));
    };

    const resyncIncomingBytes = () => {
        if (state.incomingBytes.length < state.messageByteLength) {
            return;
        }

        const frame = state.incomingBytes.slice(0, state.messageByteLength);
        const oneBytePrefix = byteArrayToHex(frame.slice(0, 1));
        const twoBytePrefix = byteArrayToHex(frame.slice(0, 2));
        const couldBeStartOfKnownFrame = state.codePrefixes.oneByte.includes(oneBytePrefix)
            || state.codePrefixes.twoBytes.includes(twoBytePrefix);

        state.incomingBytes.shift();

        if (couldBeStartOfKnownFrame) {
            console.log('Potential frame misalignment, shifted buffer by one byte.');
        }
    };

    const sendHexCommand = async (hexString, length) => {
        if (!state.serialPort || !state.isConnected || !state.writer) {
            return;
        }

        const bytes = hexToByte(hexString);
        await state.writer.write(bytes.slice(0, length));
    };

    const hexToByte = (message) => {
        const normalized = message.replace(/\s/g, '');
        const buffer = new Uint8Array(normalized.length / 2);

        for (let index = 0; index < normalized.length; index += 2) {
            buffer[index / 2] = parseInt(normalized.substr(index, 2), 16);
        }

        return buffer;
    };

    const byteArrayToHex = (bytes) => bytes
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

    const closeConnection = async () => {
        if (state.disconnecting) {
            return;
        }

        if (state.collectorEnabled || state.preparingQuestionStart) {
            return;
        }

        state.disconnecting = true;
        stopTimer();

        if (state.isReading || state.reader || state.readerStopPromise || state.stoppingCommunication) {
            await stopCommunication();
        }

        if (state.writer) {
            try {
                await state.writer.close();
            } catch (error) {
                console.error('Failed to close serial writer:', error);
            }
            state.writer = null;
        }

        if (state.serialPort) {
            try {
                await state.serialPort.close();
            } catch (error) {
                console.error('Failed to close serial port:', error);
            }
            state.serialPort = null;
        }

        state.isConnected = false;
        state.disconnecting = false;
        renderConsoleState();
    };

    const disconnectBeforeLeavingConsole = () => {
        if (state.isConnected) {
            void closeConnection();
        }
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-voting-console]') !== consoleRoot) {
            return;
        }

        if (event.target.closest('[data-usb-connect]')) {
            connect();
        }

        if (event.target.closest('[data-start-question]')) {
            event.preventDefault();
            startQuestionFromFrontend();
        }

        if (event.target.closest('[data-finish-question]')) {
            event.preventDefault();
            finishQuestionFromFrontend();
        }

        if (event.target.closest('[data-usb-disconnect]')) {
            if (state.collectorEnabled || state.preparingQuestionStart) {
                return;
            }

            closeConnection();
        }
    });

    window.addEventListener('console-state-updated', (event) => {
        const detail = event.detail?.[0] ?? event.detail ?? {};
        applyConsoleState(detail);
    });

    window.addEventListener('pagehide', disconnectBeforeLeavingConsole);
    window.addEventListener('beforeunload', disconnectBeforeLeavingConsole);
    document.addEventListener('livewire:navigating', disconnectBeforeLeavingConsole);

    if (!('serial' in navigator)) {
        console.warn('Web Serial API not supported in this browser.');
    }

    window[runtimeKey] = {
        attach,
    };

    attach(componentWire, initialConsoleState);
})();
</script>
@endscript
