@extends('layouts.print', [
    'title' => 'Výsledky hlasovania - '.$voting->name,
    'filename' => 'Vysledky hlasovania - '.$voting->name,
])

@section('content')
    <main class="mx-auto max-w-7xl px-6 py-8 print:max-w-none print:px-0 print:py-0">
        @forelse ($questions as $question)
            @php
                $results = $question->summarizedResults();
                $maxResultValue = max(
                    collect($results)->map(fn (array $result): float => (float) $result['weighted_total'])->max() ?? 0,
                    1,
                );
                $participantCount = $question->votes->unique('device_id')->count();
            @endphp

            <section class="print-page mb-8 rounded-[2rem] bg-white px-10 py-8 shadow-sm ring-1 ring-slate-200 print:mb-0 print:h-[186mm] print:overflow-hidden print:rounded-none print:px-0 print:py-0 print:shadow-none print:ring-0">
                <div class="mb-6 text-center">
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Výsledok hlasovania</p>
                    <h1 class="mt-3 text-4xl font-semibold tracking-normal text-slate-950">{{ $question->text }}</h1>
                </div>

                <div class="grid min-h-[22rem] grid-cols-3 items-end gap-12 print:min-h-[122mm]" data-export-result-chart="vertical">
                    @foreach ($results as $result)
                        @php
                            $percent = ((float) $result['weighted_total'] / $maxResultValue) * 100;
                        @endphp

                        <div class="flex h-full flex-col items-center justify-end gap-5">
                            <div class="text-center text-3xl font-semibold text-slate-950">
                                {{ $result['label'] }}
                            </div>
                            <div class="flex h-56 w-full items-end justify-center print:h-[76mm]">
                                <div class="w-28 min-w-20" style="height: {{ max($percent, 2) }}%; background-color: {{ $result['color'] ?? '#64748b' }}"></div>
                            </div>
                            <div class="text-center text-5xl font-semibold text-slate-950">
                                {{ rtrim(rtrim(number_format($result['weighted_total'], 2, ',', ' '), '0'), ',') }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mx-auto mt-6 w-max bg-white px-6 py-2 text-center text-xl font-medium text-slate-500 shadow-sm">
                    Prijaté zariadenia: {{ $participantCount }}
                </div>
            </section>
        @empty
            <section class="rounded-[2rem] bg-white px-8 py-16 text-center text-2xl font-semibold text-slate-500 shadow-sm ring-1 ring-slate-200">
                Toto hlasovanie zatiaľ nemá žiadne uzavreté otázky na export.
            </section>
        @endforelse
    </main>
@endsection
