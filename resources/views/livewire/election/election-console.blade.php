<div wire:poll.1s="liveTick" class="min-h-screen bg-slate-100 p-6">
    <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[18rem_1fr]">
        <aside class="rounded-3xl bg-white p-4 shadow-sm">
            @foreach ($contests as $item)
                <button wire:click="selectContest({{ $item->id }})"
                    class="mt-2 block w-full rounded-xl px-3 py-2 text-left {{ $contest->id === $item->id ? 'bg-emerald-600 text-white' : 'bg-slate-100' }}">
                    {{ $item->name }}
                </button>
            @endforeach
        </aside>

        <main class="rounded-3xl bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <h1 class="text-2xl font-semibold">{{ $contest->name }}</h1>
                <span class="text-sm font-semibold {{ $serialConnected ? 'text-emerald-700' : 'text-rose-700' }}">
                    {{ $serialConnected ? 'Serial Agent pripojený' : 'Serial Agent nepripojený' }}
                </span>
            </div>

            @if (session('status'))
                <p data-flash-message class="mt-3 rounded-xl bg-rose-50 p-3 text-rose-800 transition-opacity duration-300">
                    {{ session('status') }}
                </p>
            @endif

            @if (! $round)
                <button wire:click="createRound" class="mt-5 rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white">
                    Vytvoriť kolo
                </button>
            @else
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button wire:click="startRoundPausedViaHelper" @disabled(! $serialConnected || $collectorEnabled)
                        class="rounded-xl border border-slate-300 bg-slate-100 px-4 py-3 font-semibold text-slate-700 disabled:opacity-50">
                        Štart bez času
                    </button>
                    <button wire:click="startRoundViaHelper" @disabled(! $serialConnected || $timerRunning || $round->status === 'closed')
                        class="rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white disabled:opacity-50">
                        Štart
                    </button>
                    <button wire:click="pauseRoundViaHelper" @disabled(! $serialConnected || ! $timerRunning)
                        class="rounded-xl bg-amber-400 px-4 py-3 font-semibold text-slate-950 disabled:opacity-50">
                        Pauza
                    </button>
                    <button wire:click="stopRoundViaHelper" @disabled(! $serialConnected || ! $collectorEnabled)
                        class="rounded-xl bg-rose-600 px-4 py-3 font-semibold text-white disabled:opacity-50">
                        Stop
                    </button>
                    <button wire:click="showRoundResults" @disabled($round->status !== 'closed')
                        class="rounded-xl bg-sky-600 px-4 py-3 font-semibold text-white disabled:opacity-50">
                        Zobraziť výsledok kola
                    </button>
                    @if ($resultsVisible)
                        <button wire:click="hideRoundResults" class="rounded-xl border border-slate-300 px-4 py-3 font-semibold text-slate-700">
                            Skryť výsledok
                        </button>
                    @endif
                    @if ($hasNextRound)
                        <button wire:click="advanceToNextRound" class="rounded-xl border border-slate-300 px-4 py-3 font-semibold text-slate-700">
                            Ďalšie kolo
                        </button>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-4 text-slate-700">
                    <p>Kolo {{ $round->round_number }} · {{ $round->status }} · väčšina {{ $results['majority_threshold'] }}</p>
                    <p class="rounded-lg bg-slate-900 px-3 py-2 text-xl font-bold tabular-nums text-white">
                        {{ sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60) }}
                    </p>
                    @if ($collectorEnabled && ! $timerRunning)
                        <p class="font-semibold text-amber-700">Odpočet stojí</p>
                    @endif
                </div>

                <div class="mt-4 space-y-2">
                    @foreach ($results['candidates'] as $candidate)
                        <button wire:click="selectCandidate({{ $candidate['id'] }})" @disabled($collectorEnabled || $resultsVisible || $round->status === 'closed')
                            class="flex w-full justify-between rounded-xl px-4 py-3 text-left disabled:opacity-60 {{ $candidateId === $candidate['id'] ? 'bg-emerald-600 text-white' : 'bg-slate-100' }}">
                            <span>{{ $candidate['first_name'] }} {{ $candidate['last_name'] }}</span>
                            <strong>{{ $candidate['weighted_total'] }}</strong>
                        </button>
                    @endforeach
                </div>
            @endif
        </main>
    </div>
</div>
