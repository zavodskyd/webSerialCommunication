<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            @page {
                size: A4 landscape;
                margin: 12mm;
            }

            @media print {
                * {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                .no-print {
                    display: none !important;
                }

                .print-page {
                    break-after: page;
                    page-break-after: always;
                }

                .print-page:last-child {
                    break-after: auto;
                    page-break-after: auto;
                }
            }
        </style>
    </head>
    <body class="bg-slate-100 font-sans antialiased print:bg-white">
        <div class="no-print sticky top-0 z-50 border-b border-slate-200 bg-white/95 px-6 py-4 shadow-sm backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
                <p class="text-sm font-semibold text-slate-700">Tlačový náhľad exportu</p>
                <button type="button" onclick="window.print()" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                    Tlačiť / uložiť PDF
                </button>
            </div>
        </div>

        @yield('content')

        <script>
            window.addEventListener('load', () => {
                window.print();
            });
        </script>
    </body>
</html>
