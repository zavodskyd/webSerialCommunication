@extends('layouts.print', [
    'title' => 'Stlačené možnosti - '.$voting->name,
    'filename' => 'Stlacene moznosti - '.$voting->name,
])

@section('content')
    <main class="mx-auto max-w-7xl px-6 py-8 print:max-w-none print:px-0 print:py-0">
        <section class="rounded-[2rem] bg-white px-8 py-8 shadow-sm ring-1 ring-slate-200 print:rounded-none print:px-0 print:py-0 print:shadow-none print:ring-0">
            <div class="mb-8">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-slate-400">Export stlačených možností</p>
                <h1 class="mt-3 text-4xl font-semibold text-slate-950">{{ $voting->name }}</h1>
            </div>

            <table class="w-full border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b-2 border-slate-900 text-xs uppercase tracking-[0.18em] text-slate-500">
                        <th class="py-3 pr-4">Názov hlasovania</th>
                        <th class="px-4 py-3">Číslo zariadenia</th>
                        <th class="px-4 py-3 text-right">Váha</th>
                        <th class="py-3 pl-4">Stlačená možnosť</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($questions as $question)
                        @forelse ($question->votes->sortBy(fn ($vote) => $vote->device?->device_number ?? '') as $vote)
                            <tr class="border-b border-slate-200">
                                <td class="py-3 pr-4 font-semibold text-slate-900">{{ $question->text }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $vote->device?->device_number ?? 'Neznáme' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">
                                    {{ rtrim(rtrim(number_format((float) $vote->weight_snapshot, 2, ',', ' '), '0'), ',') }}
                                </td>
                                <td class="py-3 pl-4 font-semibold text-slate-900">{{ $vote->option_key }}</td>
                            </tr>
                        @empty
                            <tr class="border-b border-slate-200">
                                <td class="py-3 pr-4 font-semibold text-slate-900">{{ $question->text }}</td>
                                <td colspan="3" class="px-4 py-3 text-slate-500">Bez prijatých hlasov.</td>
                            </tr>
                        @endforelse
                    @empty
                        <tr>
                            <td colspan="4" class="py-10 text-center text-lg font-semibold text-slate-500">
                                Toto hlasovanie zatiaľ nemá žiadne uzavreté otázky na export.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </main>
@endsection
