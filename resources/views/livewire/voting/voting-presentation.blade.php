<div wire:poll.500ms class="relative h-screen overflow-hidden bg-white text-slate-950">
    @if ($question)
        @php
            $timerIsActive = $voting->runtime_collector_enabled && $voting->runtime_remaining_seconds <= 30;
            $timerIsWarning = $timerIsActive && $voting->runtime_remaining_seconds <= 5;
        @endphp

        <div class="flex h-full flex-col px-10 py-5">
            <header class="flex items-start gap-10">
                <div class="flex h-36 w-96 items-center justify-center overflow-hidden">
                    @if ($voting->logo_path)
                        <img src="{{ route('votings.logo', $voting) }}" alt="Logo hlasovania" class="h-full w-full object-contain">
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

            <main class="flex min-h-0 flex-1 flex-col pt-8">
                <div class="text-6xl font-light leading-tight tracking-normal text-slate-800">
                    {{ $voting->header_text ?: 'Hlavička hlasovania' }}
                </div>

                <div class="flex min-h-0 flex-1 flex-col justify-center pt-5">
                    <p class="mx-auto max-w-5xl text-center text-5xl font-medium leading-tight text-slate-950">
                        {{ $question->text }}
                    </p>

                    <div class="mx-auto mt-10 w-full max-w-4xl space-y-5">
                        @foreach ($question->options->sortBy('sort_order') as $option)
                            <div class="flex items-center gap-7 text-5xl font-medium text-slate-950">
                                <span class="w-20 text-right">{{ $option->key }}.</span>
                                <span class="min-w-64">{{ $option->label }}</span>
                                <span class="inline-flex h-14 w-24" style="background-color: {{ $option->color ?? '#cbd5e1' }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </main>

            <footer class="grid grid-cols-3 items-end border-t border-slate-200 pt-3">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-slate-400">Stav</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-950">
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
                        'mx-auto inline-flex min-w-72 items-center justify-center px-8 py-3 text-7xl font-semibold tracking-normal transition-colors',
                        'bg-emerald-500 text-white' => $timerIsActive && ! $timerIsWarning,
                        'bg-orange-500 text-white' => $timerIsWarning,
                        'bg-transparent text-slate-950' => ! $timerIsActive,
                    ])>
                        {{ sprintf('%02d:%02d', intdiv($voting->runtime_remaining_seconds, 60), $voting->runtime_remaining_seconds % 60) }}
                    </div>
                </div>

                <div class="text-right">
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-slate-400">Prijaté zariadenia</p>
                    <p class="mt-2 text-5xl font-semibold text-slate-950">{{ $participantCount }}</p>
                </div>
            </footer>
        </div>

        @if ($voting->runtime_results_visible)
            <div class="absolute inset-0 flex items-center justify-center bg-white/95 px-10 backdrop-blur-2xl" data-presentation-result-overlay="blurred">
                <div class="w-full max-w-6xl">
                    <div class="mb-12 text-center">
                        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Výsledok hlasovania</p>
                        <h2 class="mt-4 text-5xl font-semibold tracking-normal text-slate-950">{{ $question->text }}</h2>
                    </div>

                    <div class="grid min-h-[34rem] grid-cols-3 items-end gap-16" data-presentation-result-chart="vertical">
                        @foreach ($results as $result)
                            @php
                                $percent = ((float) $result['weighted_total'] / $maxResultValue) * 100;
                            @endphp
                            <div class="flex h-full flex-col items-center justify-end gap-8" data-presentation-result-option>
                                <div class="text-center text-4xl font-semibold text-slate-950">
                                    {{ $result['label'] }}
                                </div>
                                <div class="flex h-80 w-full items-end justify-center" data-presentation-result-bar-space>
                                    <div class="w-32 min-w-24 transition-all" style="height: {{ max($percent, 2) }}%; background-color: {{ $result['color'] ?? '#64748b' }}"></div>
                                </div>
                                <div class="text-center text-6xl font-semibold text-slate-950">
                                    {{ rtrim(rtrim(number_format($result['weighted_total'], 2, ',', ' '), '0'), ',') }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mx-auto mt-10 w-max bg-white px-8 py-3 text-center text-2xl font-medium text-slate-500 shadow-sm" data-presentation-result-participants>
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
