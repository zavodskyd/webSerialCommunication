<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php($printTitle = $title ?? config('app.name', 'Hlasovanie'))
        @php($pdfFilename = $filename ?? $printTitle)
        <title>{{ $printTitle }}</title>

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
            <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-700">Tlačový náhľad exportu</p>
                    <p class="mt-1 text-xs text-slate-500">Export PDF vytvorí súbor priamo z tejto obrazovky s pevnou orientáciou A4 na šírku.</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="export-pdf-button" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                        Exportovať PDF
                    </button>

                    <button type="button" onclick="window.print()" class="rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 transition hover:bg-slate-50">
                        Tlačiť cez systém
                    </button>
                </div>
            </div>
        </div>

        @yield('content')

        <script>
            const printTitle = @js($printTitle);
            const pdfFilename = @js($pdfFilename);

            const normalizeFilename = (value) => {
                return String(value || 'Hlasovanie')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[\\/:*?"<>|]+/g, '-')
                    .replace(/\s+/g, ' ')
                    .trim();
            };

            const exportPdfButton = document.getElementById('export-pdf-button');

            document.title = normalizeFilename(printTitle);

            exportPdfButton?.addEventListener('click', async () => {
                const filename = `${normalizeFilename(pdfFilename)}.pdf`;

                if (window.electron?.ipcRenderer) {
                    await window.electron.ipcRenderer.invoke('print-to-pdf', {
                        filename,
                        landscape: true,
                        pageSize: 'A4',
                        printBackground: true,
                    });

                    return;
                }

                window.print();
            });

            window.addEventListener('beforeprint', () => {
                document.title = normalizeFilename(printTitle);
                document.documentElement.classList.add('is-printing');
            });

            window.addEventListener('afterprint', () => {
                document.documentElement.classList.remove('is-printing');
            });
        </script>
    </body>
</html>
