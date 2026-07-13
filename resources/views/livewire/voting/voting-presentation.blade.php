<div wire:poll.500ms class="relative h-screen overflow-hidden bg-white text-slate-950">
    @if ($contest)
        <div class="flex h-full flex-col px-10 py-5">
            <header class="flex items-start gap-10">
                <div class="flex h-36 w-96 items-center justify-center">
                    @if ($voting->logo_path)
                        <img src="{{ route('votings.logo', $voting) }}" alt="Logo hlasovania"
                            class="h-full w-full object-contain">
                    @endif
                </div>
                <div class="flex min-h-36 flex-1 flex-col items-start justify-center">
                    <h1 class="text-4xl font-semibold">{{ $voting->title ?: $voting->name }}</h1>
                    <p class="mt-2 text-3xl font-semibold text-emerald-700">{{ $contest->name }}</p>
                </div>
            </header>
            <main class="mt-6 grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_18rem] gap-8">
                <div>
                    <p class="text-2xl text-slate-500">Kandidátka</p>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        @foreach ($contest->candidates as $candidate)
                            <div class="rounded-2xl bg-slate-100 px-6 py-4 text-3xl">{{ $candidate->first_name }}
                                {{ $candidate->last_name }}</div>
                        @endforeach
                    </div>
                </div>
                <aside class="flex flex-col justify-between border-l pl-6 text-right">
                    <div class="text-2xl">Mandátov: <strong>{{ $contest->seat_count }}</strong></div>
                    <div class="text-xl text-slate-500">Môžete označiť najviac {{ $contest->seat_count }} kandidátov.</div>
                </aside>
            </main>
        </div>
    @elseif ($round)
        <div class="flex h-full flex-col px-10 py-5">
            <header class="flex items-start gap-10">
                <div class="flex h-36 w-96 items-center justify-center">
                    @if ($voting->logo_path)
                        <img src="{{ route('votings.logo', $voting) }}" alt="Logo hlasovania"
                            class="h-full w-full object-contain">
                    @endif
                </div>
                <div class="flex min-h-36 flex-1 flex-col items-start justify-center">
                    <h1 class="text-4xl font-semibold">{{ $voting->title ?: $voting->name }}</h1>
                    <p class="mt-2 text-3xl font-semibold text-emerald-700">{{ $round->contest->name }} · kolo
                        {{ $round->round_number }}</p>
                </div>
            </header>
            <main class="mt-6 grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_18rem] gap-8">
                @php
                    $candidateCount = count($roundResults['candidates']);
                    $timerIsActive = $voting->runtime_collector_enabled && $voting->runtime_remaining_seconds <= 30;
                    $timerIsWarning = $timerIsActive && $voting->runtime_remaining_seconds <= 5;
                @endphp
                <div
                    x-data="{
                        candidateCount: {{ $candidateCount }},
                        compact: {{ $candidateCount >= 8 ? 'true' : 'false' }},
                        columns: 1,
                        rows: 1,
                        observer: null,
                        configure() {
                            if (!this.compact) {
                                this.rows = this.candidateCount;
                                this.columns = 1;

                                return;
                            }

                            const availableHeight = this.$refs.candidateRows.clientHeight;
                            const targetRowHeight = window.innerHeight < 800 ? 52 : 68;

                            this.rows = Math.max(1, Math.min(this.candidateCount, Math.floor(availableHeight / targetRowHeight)));
                            this.columns = Math.max(1, Math.ceil(this.candidateCount / this.rows));
                        },
                        init() {
                            if (!this.compact) {
                                return;
                            }

                            this.$nextTick(() => {
                                this.configure();
                                this.observer = new ResizeObserver(() => this.configure());
                                this.observer.observe(this.$refs.candidateRows);
                            });
                        },
                        destroy() {
                            this.observer?.disconnect();
                        },
                    }"
                    data-election-candidate-table
                    @class([
                        'flex min-h-0 flex-col overflow-hidden' => $candidateCount >= 8,
                    ])
                >
                    <div class="grid grid-cols-[5rem_1fr_12rem_10rem] gap-4 border-b pb-3 text-xl font-semibold text-slate-500">
                        <span>Por.</span><span>Kandidát</span><span>Hlasy</span><span>Stav</span>
                    </div>
                    <div
                        x-ref="candidateRows"
                        :style="compact ? `grid-template-columns: repeat(${columns}, minmax(0, 1fr)); grid-template-rows: repeat(${rows}, minmax(0, 1fr));` : ''"
                        @class([
                            'grid grid-flow-col min-h-0 flex-1 gap-x-6 overflow-hidden' => $candidateCount >= 8,
                            'grid grid-cols-1 gap-x-6' => $candidateCount < 8,
                        ])
                    >
                        @foreach ($roundResults['candidates'] as $index => $candidate)
                            @php
                                $sourceCandidateId = $roundCandidateSourceIds[$candidate['id']] ?? null;
                                $isActiveCandidate = ! $roundResultsVisible && $activeRoundCandidateId === $candidate['id'];
                                $isCurrentWinner = $roundResultsVisible && $candidate['elected'];
                                $isPriorWinner = $roundResultsVisible && in_array($sourceCandidateId, $priorElectedCandidateIds, true);
                            @endphp
                            <div @class([
                                'grid min-h-0 items-center gap-3 overflow-hidden border-b px-3 transition-colors',
                                'bg-emerald-100' => $isActiveCandidate || $isCurrentWinner,
                                'bg-amber-100' => $isPriorWinner && ! $isCurrentWinner,
                            ])
                                :class="{
                                    'grid-cols-[2rem_minmax(0,1fr)_4rem] py-1 text-base': compact && (columns >= 3 || rows >= 9),
                                    'grid-cols-[2.5rem_minmax(0,1fr)_5rem] py-2 text-xl': compact && (columns === 2 || rows >= 6),
                                    'grid-cols-[3rem_minmax(0,1fr)_7rem_7rem] py-3 text-2xl': !compact || (columns === 1 && rows < 6),
                                }"
                            >
                                <span>{{ $index + 1 }}</span>
                                <span class="truncate">{{ $candidate['first_name'] }} {{ $candidate['last_name'] }}</span>
                                <strong>{{ $candidate['weighted_total'] }}</strong>
                                <span x-show="!compact || (columns === 1 && rows < 6)">{{ $isPriorWinner ? 'Zvolený skôr' : ($isCurrentWinner ? 'Zvolený' : '') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <aside class="flex flex-col justify-between border-l pl-6 text-right">
                    <div class="space-y-6">
                        <div @class([
                            'ml-auto inline-flex min-w-64 items-center justify-center px-6 py-3 text-5xl font-semibold tracking-normal transition-colors',
                            'bg-emerald-500 text-white' => $timerIsActive && ! $timerIsWarning,
                            'bg-orange-500 text-white' => $timerIsWarning,
                            'bg-transparent text-slate-950' => ! $timerIsActive,
                        ])>
                            {{ sprintf('%02d:%02d', intdiv($voting->runtime_remaining_seconds, 60), $voting->runtime_remaining_seconds % 60) }}
                        </div>
                        <p class="border-t border-slate-200 pt-6 text-xl text-slate-950">
                            Možno označiť najviac
                            <strong class="text-5xl font-bold text-slate-950">{{ $round->contest->seat_count }}</strong>
                            kandidátov.
                        </p>
                    </div>
                    <div class="text-xl text-slate-500">
                        Zariadení s platným hlasom: <strong class="text-3xl font-bold text-slate-950">{{ $roundResults['accepted_device_count'] }}</strong>
                    </div>
                </aside>
            </main>
        </div>
    @elseif ($admission)
        @php
            $remaining =
                $admission->status === 'live' && $admission->opened_at
                    ? max(
                        0,
                        $admission->response_time_seconds -
                            (now()->getTimestamp() - $admission->opened_at->getTimestamp()),
                    )
                    : $admission->response_time_seconds;
        @endphp
        <div class="flex h-full flex-col px-10 py-5">
            <header class="flex items-start gap-10">
                <div class="flex h-36 w-96 items-center justify-center overflow-hidden">
                    @if ($voting->logo_path)
                        <img src="{{ route('votings.logo', $voting) }}" alt="Logo hlasovania"
                            class="h-full w-full object-contain">
                    @endif
                </div>
                <div class="flex min-h-36 flex-1 items-center justify-end text-right">
                    <h1 class="max-w-4xl text-5xl font-medium text-slate-950">
                        {{ $voting->title ?: 'Hlasovanie delegátov' }}</h1>
                </div>
            </header>
            <main class="flex flex-1 items-center justify-center">
                <div class="w-full max-w-5xl text-left">
                    <p class="text-center text-3xl font-semibold text-emerald-700">Doplnenie kandidáta ·
                        {{ $admission->contest->name }}</p>
                    <h2 class="mt-8 text-center text-6xl font-semibold">{{ $admission->first_name }}
                        {{ $admission->last_name }}</h2>
                    @if ($admission->results_visible)
                        <div class="mt-14 grid w-full max-w-5xl grid-cols-3 gap-8">
                            @foreach ($admissionResults as $result)
                                <div class="rounded-2xl bg-slate-100 p-8">
                                    <p class="text-2xl">{{ $result['label'] }}</p>
                                    <p class="mt-3 text-6xl font-semibold">{{ $result['weighted_total'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-14 space-y-5 text-left text-4xl">
                            <p>A. Za</p>
                            <p>B. Proti</p>
                            <p>C. Zdržal sa</p>
                        </div>
                    @endif
                </div>
            </main>
            <footer class="grid grid-cols-3 items-end border-t border-slate-200 pt-2">
                <p class="text-xl font-semibold">
                    {{ $admission->status === 'live' ? 'Prebieha hlasovanie' : ($admission->results_visible ? 'Výsledok' : 'Zastavené') }}
                </p>
                <p class="text-center text-4xl font-semibold">
                    {{ sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60) }}</p>
                <p class="text-right text-3xl font-semibold">{{ $admission->votes()->count() }}</p>
            </footer>
        </div>
    @elseif ($question)
        @php
            $timerIsActive = $voting->runtime_collector_enabled && $voting->runtime_remaining_seconds <= 30;
            $timerIsWarning = $timerIsActive && $voting->runtime_remaining_seconds <= 5;
        @endphp

        <div class="flex h-full flex-col px-10 py-5">
            <header class="flex items-start gap-10">
                <div class="flex h-36 w-96 items-center justify-center overflow-hidden">
                    @if ($voting->logo_path)
                        <img src="{{ route('votings.logo', $voting) }}" alt="Logo hlasovania"
                            class="h-full w-full object-contain">
                    @else
                        <div class="text-sm font-semibold uppercase tracking-[0.35em] text-slate-400">
                            Logo
                        </div>
                    @endif
                </div>

                <div class="flex min-h-36 flex-1 items-center justify-end text-right">
                    <h1 class="max-w-4xl text-5xl font-medium leading-tight tracking-normal text-slate-950">
                        {{ $voting->title ?: 'Hlasovanie delegátov' }}
                    </h1>
                </div>
            </header>

            <main class="relative min-h-0 flex-1 pt-8">
                <div
                    class="absolute left-0 top-[12%] max-w-5xl text-5xl font-light leading-tight tracking-normal text-slate-800">
                    {{ $voting->header_text ?: 'Hlavička hlasovania' }}
                </div>

                <div class="absolute left-1/2 top-[40%] w-full max-w-[54rem] -translate-x-1/2">
                    <p class="mx-auto mb-12 max-w-5xl text-center text-5xl font-medium leading-tight text-slate-950">
                        {{ $question->text }}
                    </p>

                    <div class="mx-auto w-full max-w-6xl">
                        <div class="mx-auto w-fit space-y-4">
                            @foreach ($question->options->sortBy('sort_order') as $option)
                                <div class="flex items-center gap-14 text-5xl font-medium text-slate-950">
                                    <span class="w-20 text-left">{{ $option->key }}.</span>
                                    <span class="min-w-64">{{ $option->label }}</span>
                                    <span class="inline-flex h-10 w-16"
                                        style="background-color: {{ $option->color ?? '#cbd5e1' }}"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </main>

            <footer class="grid grid-cols-3 items-end border-t border-slate-200 pt-2">
                <div>
                    {{-- <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Stav</p> --}}
                    <p class="mt-1 text-xl font-semibold text-slate-950">
                        @if ($voting->runtime_collector_enabled && $voting->runtime_timer_running)
                            Prebieha hlasovanie
                        @elseif ($voting->runtime_collector_enabled)
                            Pauza
                        @elseif ($voting->runtime_results_visible)
                            Výsledok
                        @else
                            Pripravené
                        @endif
                    </p>
                </div>

                <div class="text-center">
                    <div @class([
                        'mx-auto inline-flex min-w-52 items-center justify-center px-5 py-2 text-4xl font-semibold tracking-normal transition-colors',
                        'bg-emerald-500 text-white' => $timerIsActive && !$timerIsWarning,
                        'bg-orange-500 text-white' => $timerIsWarning,
                        'bg-transparent text-slate-950' => !$timerIsActive,
                    ])>
                        {{ sprintf('%02d:%02d', intdiv($voting->runtime_remaining_seconds, 60), $voting->runtime_remaining_seconds % 60) }}
                    </div>
                </div>

                <div class="text-right">
                    {{-- <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Prijaté zariadenia</p> --}}
                    <p class="mt-1 text-3xl font-semibold text-slate-950">{{ $participantCount }}</p>
                </div>
            </footer>
        </div>

        @if ($voting->runtime_results_visible)
            <div class="absolute inset-0 flex items-center justify-center bg-white/90 px-6 backdrop-blur-2xl"
                data-presentation-result-overlay="blurred">
                <div class="w-full max-w-7xl">
                    <div class="mb-12 text-center">
                        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Výsledok hlasovania
                        </p>
                        <h2 class="mt-4 text-5xl font-semibold tracking-normal text-slate-950">{{ $question->text }}
                        </h2>
                    </div>

                    <div class="grid min-h-[34rem] grid-cols-3 items-end gap-16"
                        data-presentation-result-chart="vertical">
                        @foreach ($results as $result)
                            @php
                                $percent = ((float) $result['weighted_total'] / $maxResultValue) * 100;
                            @endphp
                            <div class="flex h-full flex-col items-center justify-end gap-8"
                                data-presentation-result-option>
                                <div class="text-center text-6xl font-semibold text-slate-950">
                                    {{ rtrim(rtrim(number_format($result['weighted_total'], 2, ',', ' '), '0'), ',') }}
                                </div>
                                <div class="flex h-80 w-full items-end justify-center"
                                    data-presentation-result-bar-space>
                                    <div class="w-32 min-w-24 transition-all"
                                        style="height: {{ max($percent, 2) }}%; background-color: {{ $result['color'] ?? '#64748b' }}">
                                    </div>
                                </div>
                                <div class="text-center text-4xl font-semibold text-slate-950">
                                    {{ $result['label'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mx-auto mt-10 w-max bg-white px-8 py-3 text-center text-2xl font-medium text-slate-500 shadow-sm"
                        data-presentation-result-participants>
                        Prijaté zariadenia: {{ $participantCount }}
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="flex min-h-screen items-center justify-center px-8 text-center text-3xl font-medium text-slate-500">
            Najprv priprav aspoň jednu otázku v editore hlasovania.
        </div>
    @endif
</div>
