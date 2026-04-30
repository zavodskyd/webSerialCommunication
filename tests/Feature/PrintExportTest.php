<?php

test('print layout prefers desktop ipc pdf export with explicit filename', function () {
    $layout = file_get_contents(resource_path('views/layouts/print.blade.php'));

    expect($layout)
        ->toContain('if (window.electron?.ipcRenderer) {')
        ->toContain("await window.electron.ipcRenderer.invoke('print-to-pdf', {")
        ->toContain('filename,')
        ->toContain('landscape: true,')
        ->toContain("pageSize: 'A4',")
        ->toContain('printBackground: true,')
        ->toContain('if (window.NativePHP?.printToPDF) {');

    expect(strpos($layout, 'if (window.electron?.ipcRenderer) {'))
        ->toBeLessThan(strpos($layout, 'if (window.NativePHP?.printToPDF) {'));
});

test('native electron pdf export keeps save dialog filename and landscape defaults', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));

    expect($mainProcess)
        ->toContain("title: 'Uložiť PDF',")
        ->toContain("defaultPath: safeFilename.endsWith('.pdf') ? safeFilename : `\${safeFilename}.pdf`,")
        ->toContain('landscape: options.landscape ?? true,')
        ->toContain("pageSize: options.pageSize || 'A4',")
        ->toContain('printBackground: options.printBackground ?? true,');
});
