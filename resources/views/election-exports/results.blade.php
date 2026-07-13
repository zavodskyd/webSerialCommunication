@extends('layouts.print', [
    'title' => 'Výsledky volieb - '.$voting->name,
    'filename' => 'Vysledky volieb - '.$voting->name,
    'exportPdfUrl' => $exportPdfUrl,
    'showPrintToolbar' => $showPrintToolbar,
    'showPrintScript' => $showPrintScript,
    'inlineAppCss' => $inlineAppCss,
])

@section('content')
    <main class="mx-auto max-w-7xl px-6 py-8 print:max-w-none print:px-0 print:py-0">
        @forelse ($roundResults as $item)
            <section class="print-page mb-8 rounded-[2rem] bg-white px-10 py-8 shadow-sm ring-1 ring-slate-200 print:mb-0 print:min-h-[186mm] print:rounded-none print:px-0 print:py-0 print:shadow-none print:ring-0">
                <header class="mb-6 border-b border-slate-200 pb-5">
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Výsledok volieb</p>
                    <h1 class="mt-2 text-4xl font-semibold text-slate-950">{{ $item['round']->contest->name }} · kolo {{ $item['round']->round_number }}</h1>
                    <p class="mt-2 text-lg text-slate-600">Oprávnená váha: {{ $item['results']['total_weight'] }} · nadpolovičná väčšina: {{ $item['results']['majority_threshold'] }}</p>
                </header>

                <table class="w-full border-collapse text-left text-xl">
                    <thead><tr class="border-b-2 border-slate-300 text-slate-500"><th class="px-3 py-3">Por.</th><th class="px-3 py-3">Kandidát</th><th class="px-3 py-3 text-right">Hlasy</th><th class="px-3 py-3">Stav</th></tr></thead>
                    <tbody>
                        @foreach ($item['results']['candidates'] as $index => $candidate)
                            <tr @class(['border-b border-slate-200', 'bg-emerald-100' => $candidate['elected']])>
                                <td class="px-3 py-3">{{ $index + 1 }}</td>
                                <td class="px-3 py-3 font-semibold">{{ $candidate['first_name'] }} {{ $candidate['last_name'] }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ $candidate['weighted_total'] }}</td>
                                <td class="px-3 py-3">{{ $candidate['elected'] ? 'Zvolený/á' : 'Nezvolený/á' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @empty
            <section class="rounded-[2rem] bg-white px-8 py-16 text-center text-2xl font-semibold text-slate-500 shadow-sm ring-1 ring-slate-200">
                Voľby zatiaľ nemajú žiadne uzavreté kolo na export.
            </section>
        @endforelse
    </main>
@endsection
