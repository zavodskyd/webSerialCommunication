<?php

test('serial agent workflow reuses an executable cached by rust source hash', function () {
    $workflow = file_get_contents(base_path('.github/workflows/serial-agent.yml'));
    $cacheMissCondition = "steps.serial-agent-binary-cache.outputs.cache-hit != 'true'";

    expect($workflow)
        ->toContain('id: serial-agent-binary-cache')
        ->toContain('uses: actions/cache@v5')
        ->toContain('path: extras/serial-agent/serial-agent.exe')
        ->toContain("key: windows-serial-agent-x64-msvc-v1-\${{ hashFiles('serial-agent/**') }}")
        ->toContain('uses: Swatinem/rust-cache@v2')
        ->toContain('workspaces: |')
        ->toContain('serial-agent -> target')
        ->toContain('Test-Path $agentPath -PathType Leaf')
        ->and(substr_count($workflow, $cacheMissCondition))->toBe(5);
});
