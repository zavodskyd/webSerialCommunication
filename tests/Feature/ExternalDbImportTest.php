<?php

use App\Models\Device;
use Illuminate\Http\UploadedFile;

test('external sqlite import stores nullable codes as empty strings', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'external-db-');

    try {
        $sqlite = new SQLite3($databasePath);
        $sqlite->exec('
            CREATE TABLE SKDP_ParentZariadenie (
                Id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                UniqueId VARCHAR,
                A_Code VARCHAR,
                B_Code VARCHAR,
                C_Code VARCHAR,
                D_Code VARCHAR,
                E_Code VARCHAR,
                F_Code VARCHAR,
                Ruka_Code VARCHAR,
                Vaha INTEGER
            )
        ');
        $sqlite->exec("
            INSERT INTO SKDP_ParentZariadenie
                (UniqueId, A_Code, B_Code, C_Code, D_Code, E_Code, F_Code, Ruka_Code, Vaha)
            VALUES
                ('326', '3486b2', '3496a2', '34a692', NULL, NULL, NULL, NULL, 1)
        ");
        $sqlite->close();

        $upload = new UploadedFile(
            $databasePath,
            'external.sqlite',
            'application/vnd.sqlite3',
            null,
            true
        );

        $this->post(route('load.external.db'), [
            'db_file' => $upload,
        ])
            ->assertRedirect();

        expect(Device::query()->first())
            ->device_number->toBe('326')
            ->code_a->toBe('3486b2')
            ->code_b->toBe('3496a2')
            ->code_c->toBe('34a692')
            ->code_d->toBe('')
            ->code_e->toBe('')
            ->code_f->toBe('')
            ->code_ruka->toBe('');
    } finally {
        @unlink($databasePath);
    }
});
