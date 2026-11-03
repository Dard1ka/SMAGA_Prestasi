<?php

declare(strict_types=1);

/*
 * Contoh data akun sekolah untuk DataUjiSekolahSeeder dan EmailStafSeeder (smaga_server). Data sungguhan (email staf
 * dari sekolah, kata sandi akun uji, kotak surat pengirim sekolah) ada di akun-sekolah.php di folder yang sama: file
 * lokal yang tidak pernah masuk git (.gitignore) dan dikirim terpisah. Tanpa file itu kedua seeder memakai contoh fiktif
 * di bawah (alamat .test tetap di Mailpit, tidak pernah keluar).
 */
return [
    // Alamat uji guru dan siswa uji, juga keenam staf selama saklar STH_SEED_STAF_UJI menyala:
    // <trial_mailbox>+<tanda>@<trial_domain>, mis. uji+guru1@sth.test.
    'trial_mailbox' => 'uji',
    'trial_domain' => 'sth.test',

    // Kata sandi awal keenam akun staf, akun guru uji, dan siswa uji yang sudah aktivasi (siswa belum aktivasi:
    // SMAGA_ + tanggal lahir, D-173).
    'staff_password' => 'contoh-sandi-staf',
    'guru_password' => 'contoh-sandi-guru',
    'activated_student_password' => 'contoh-sandi-siswa',

    // username => email asli dari sekolah, untuk keenam akun staf.
    'staff_emails' => [
        'admin' => 'admin@sth.test',
        'kepsek' => 'kepsek@sth.test',
        'humas' => 'humas@sth.test',
        'kesiswaan' => 'kesiswaan@sth.test',
        'tu1' => 'tu1@sth.test',
        'tu2' => 'tu2@sth.test',
    ],
];
