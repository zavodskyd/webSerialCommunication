<?php

test('serial agent workflow configures rust cache with explicit workspace mapping', function () {
    $workflow = file_get_contents(base_path('.github/workflows/serial-agent.yml'));

    expect($workflow)
        ->toContain('uses: Swatinem/rust-cache@v2')
        ->toContain('workspaces: |')
        ->toContain('serial-agent -> target');
});
