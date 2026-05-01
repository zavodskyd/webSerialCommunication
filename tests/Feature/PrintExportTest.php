<?php

test('print layout uses a route-based native pdf export and keeps system print fallback separate', function () {
    $layout = file_get_contents(resource_path('views/layouts/print.blade.php'));

    expect($layout)
        ->toContain('<base href="{{ rtrim(url(\'/\'), \'/\') }}/">')
        ->toContain('@if ($inlineAppCss !== null)')
        ->toContain('{!! $inlineAppCss !!}')
        ->toContain('@vite([\'resources/css/app.css\', \'resources/js/app.js\'])')
        ->toContain('disabled:cursor-not-allowed disabled:bg-slate-400 disabled:hover:bg-slate-400')
        ->toContain('const exportPdfUrl = @js($exportPdfUrl);')
        ->toContain('let exportPdfInProgress = false;')
        ->toContain('if (exportPdfInProgress) {')
        ->toContain('exportPdfButton.disabled = isExporting;')
        ->toContain("exportPdfButton.textContent = isExporting ? 'Exportujem PDF...' : exportPdfButtonLabel;")
        ->toContain("exportPdfButton.setAttribute('aria-busy', isExporting ? 'true' : 'false');")
        ->toContain('setExportPdfState(true);')
        ->toContain('setExportPdfState(false);')
        ->toContain('if (! exportPdfUrl || ! window.axios) {')
        ->toContain('const response = await window.axios.get(exportPdfUrl, {')
        ->toContain("Accept: 'application/json',")
        ->toContain('window.alert(`PDF bolo uložené do:\n${response.data.path}`);')
        ->toContain('onclick="window.print()"');

    expect($layout)
        ->not->toContain("window.electron.ipcRenderer.invoke('print-to-pdf'")
        ->not->toContain('if (window.NativePHP?.printToPDF) {');
});

test('results and pressed-options exports pass their native pdf endpoints into the print layout', function () {
    $resultsView = file_get_contents(resource_path('views/voting-exports/results.blade.php'));
    $pressedOptionsView = file_get_contents(resource_path('views/voting-exports/pressed-options.blade.php'));

    expect($resultsView)
        ->toContain("route('votings.exports.results.pdf', \$voting)")
        ->toContain("'showPrintToolbar' => \$showPrintToolbar ?? true")
        ->toContain("'showPrintScript' => \$showPrintScript ?? true");

    expect($pressedOptionsView)
        ->toContain("route('votings.exports.pressed-options.pdf', \$voting)")
        ->toContain("'showPrintToolbar' => \$showPrintToolbar ?? true")
        ->toContain("'showPrintScript' => \$showPrintScript ?? true");
});

test('native electron pdf export keeps save dialog filename and landscape defaults', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));
    $systemApi = file_get_contents(base_path('nativephp/electron/electron-plugin/src/server/api/system.ts'));

    expect($mainProcess)
        ->toContain("title: 'Uložiť PDF',")
        ->toContain("defaultPath: safeFilename.endsWith('.pdf') ? safeFilename : `\${safeFilename}.pdf`,")
        ->toContain('landscape: options.landscape ?? true,')
        ->toContain("pageSize: options.pageSize || 'A4',")
        ->toContain('printBackground: options.printBackground ?? true,');

    expect($systemApi)
        ->toContain('data:text/html;charset=UTF-8;base64,${html}')
        ->not->toContain('data:text/html;base64;charset=UTF-8,${html}');
});
