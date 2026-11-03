<?php

declare(strict_types=1);

use App\Rules\NoWebAddress;

/*
| QA10: D-76 missed common link shorteners and country domains (shorturl.at, rb.gy, is.gd, youtu.be, lnkd.in, .ru,
| .cn …) and any "host.tld/path". Mail clients turn those into links, so a lomba name carrying one is refused. The
| other side matters as much: ordinary lomba names with abbreviations, degrees and numbers stay allowed.
*/

it('finds link shorteners, country domains and host/path addresses in a name (QA10)', function (string $name): void {
    expect(NoWebAddress::found($name))->toBeTrue();
})->with([
    'shorturl.at' => 'Daftar di shorturl.at/abc12',
    'rb.gy' => 'Formulir rb.gy/x7k2',
    'is.gd' => 'Info is.gd/lomba26',
    'youtu.be' => 'Video juknis youtu.be/dQw4w9',
    'lnkd.in' => 'Info lnkd.in/eFgHiJ',
    'bare shortener' => 'Lomba Sains rb.gy',
    '.ru host' => 'Olimpiade situs-lomba.ru 2026',
    '.cn host' => 'Lomba Robotik robot-lomba.cn',
    'host with path, unlisted domain' => 'Pendaftaran panitia.zz/daftar',
    'free domain' => 'Hadiah klaim-hadiah.tk sekarang',
]);

it('keeps ordinary lomba names with abbreviations, degrees and numbers (QA10)', function (string $name): void {
    expect(NoWebAddress::found($name))->toBeFalse();
})->with([
    'O2SN 2026',
    'Lomba 1.0',
    'FLS3N Tk. Provinsi',
    'Lomba Debat bersama Dra. Sri Wahyuni, S.Pd.',
    'Seminar Riset oleh Hendra Kusuma, S.Pd., M.Si.',
    'Lomba Seni Rupa, juri M.Sn. dan S.Th.',
    'Pembina Dr.Ir. Budi Santoso',
    'Lomba Debat Tk.SMA/SMK se-Jateng',
    'Olimpiade Tk.SD/MI Kota Semarang',
    'Lomba Cerdas Cermat Tk.MA 2026',
    'Lomba Debat Vol.II/2026',
    'KSN-K Tk. Kota Semarang 2026',
    'Lomba Web Design Vol.2',
    'S.T.E.M Fair 4.0',
    'Kompetisi Sains Madrasah 2026 (KSM)',
    'Olimpiade Matematika Tk. Nasional',
]);
