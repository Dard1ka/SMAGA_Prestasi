<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What an organizer hands out about a lomba, for the demo data (LOCAL ONLY): the juknis (petunjuk teknis, several
 * pages), a one-page brochure, a one-page poster, and a plain one-page note for a file nothing else fits.
 *
 * The wording is ours and follows the kind of lomba (olimpiade, riset, robotik, seni, film, debat, olahraga, bela
 * diri), which is read from the bidang and the name. The schedule is built from the lomba's own dates. Contact
 * data is obviously fictional: an example.test address and a phone number that says it is a sample.
 */
final class DemoGuideline
{
    public const EMAIL = 'panitia@example.test';

    public const PHONE = '0800-000-0000 (nomor contoh)';

    /** bidang => [primary, accent, dark, soft] */
    private const PALETTES = [
        'sains' => ['#1f4e8c', '#e0a526', '#12305a', '#dce7f5'],
        'riset' => ['#1e8a5a', '#f2b632', '#0f5c3c', '#d9f0e4'],
        'seni_budaya' => ['#7b1e2b', '#e08a1e', '#4a0f18', '#f6e3cf'],
        'olahraga' => ['#b3261e', '#f2b632', '#6e1410', '#f6dedc'],
    ];

    private const EMBLEMS = [
        'olimpiade' => 'atom', 'riset' => 'lampu', 'robot' => 'lampu', 'seni' => 'bunga', 'film' => 'bunga',
        'debat' => 'buku', 'olahraga' => 'obor', 'beladiri' => 'obor', 'umum' => 'perisai',
    ];

    private const PURPOSES = [
        'olimpiade' => 'mengukur kemampuan, menumbuhkan budaya belajar, dan menjaring bibit unggul di bidang sains',
        'riset' => 'menuangkan gagasan, meneliti masalah di sekitarnya, dan menyampaikan hasilnya secara ilmiah',
        'robot' => 'merancang, membuat, dan memprogram robot untuk menyelesaikan tantangan yang diberikan',
        'seni' => 'menampilkan karya terbaik, mengasah kreativitas, dan menumbuhkan kecintaan pada seni dan budaya',
        'film' => 'bercerita melalui film pendek dan mengasah kemampuan membuat karya audio-visual',
        'debat' => 'berpikir kritis, menyusun argumen, dan menyampaikan pendapat secara santun',
        'olahraga' => 'bertanding secara sportif, mengukur hasil latihan, dan menjaring atlet pelajar berprestasi',
        'beladiri' => 'bertanding secara sportif, mengukur hasil latihan, dan menjaring atlet pelajar berprestasi',
        'umum' => 'menunjukkan kemampuan terbaiknya dan belajar dari peserta lain',
    ];

    private const TAGLINES = [
        'olimpiade' => 'Uji kemampuanmu, raih prestasi terbaikmu.',
        'riset' => 'Dari rasa ingin tahu menjadi karya yang bermanfaat.',
        'robot' => 'Rancang, rakit, dan taklukkan tantangannya.',
        'seni' => 'Panggung untuk karya dan kreativitasmu.',
        'film' => 'Ceritakan duniamu dalam satu film pendek.',
        'debat' => 'Sampaikan gagasanmu dengan argumen terbaik.',
        'olahraga' => 'Bertanding sportif, raih prestasi tertinggi.',
        'beladiri' => 'Bertanding sportif, raih prestasi tertinggi.',
        'umum' => 'Tunjukkan kemampuan terbaikmu.',
    ];

    private const CATEGORIES = [
        'olimpiade' => ['Matematika', 'Fisika, Kimia, dan Biologi', 'Informatika dan Astronomi'],
        'riset' => ['Sains dan teknologi', 'Lingkungan dan energi', 'Sosial humaniora'],
        'robot' => ['Robot pengikut garis', 'Robot pemindah barang', 'Robot kreatif'],
        'seni' => ['Penampilan tunggal', 'Penampilan kelompok', 'Penampilan favorit penonton'],
        'film' => ['Film fiksi pendek', 'Film dokumenter pendek', 'Poster film terbaik'],
        'debat' => ['Tim terbaik', 'Pembicara terbaik', 'Tim favorit'],
        'olahraga' => ['Putra', 'Putri', 'Beregu'],
        'beladiri' => ['Kelas tanding putra', 'Kelas tanding putri', 'Seni tunggal'],
        'umum' => ['Perorangan', 'Tim', 'Favorit'],
    ];

    private const MECHANICS = [
        'olimpiade' => [
            'Peserta melakukan daftar ulang paling lambat 30 menit sebelum tes dimulai.',
            'Babak penyisihan berupa tes tertulis pilihan ganda selama 90 menit.',
            'Peserta dengan nilai tertinggi maju ke babak final berupa soal uraian dan pemecahan masalah selama 120 menit.',
            'Peserta hanya membawa alat tulis. Kalkulator dan alat komunikasi tidak diperkenankan, kecuali ditentukan lain oleh panitia.',
            'Hasil tiap babak diumumkan panitia pada hari yang sama.',
        ],
        'riset' => [
            'Peserta mengirim naskah karya tulis dalam bentuk PDF melalui laman pendaftaran sebelum pendaftaran ditutup.',
            'Naskah diseleksi oleh dewan juri. Finalis diumumkan sebelum hari pelaksanaan.',
            'Finalis mempresentasikan karyanya selama 10 menit, dilanjutkan tanya jawab 10 menit di depan dewan juri.',
            'Finalis menyiapkan poster ilmiah ukuran A1 dan, bila ada, purwarupa karyanya.',
            'Karya harus asli, belum pernah menjadi juara pada lomba sejenis, dan bebas plagiarisme.',
        ],
        'robot' => [
            'Tim melakukan daftar ulang dan pemeriksaan robot sebelum babak pertama.',
            'Robot dirakit dan diprogram oleh peserta sendiri. Ukuran dan berat mengikuti lampiran teknis dari panitia.',
            'Babak penyisihan memakai sistem poin dari dua kali percobaan di lintasan.',
            'Delapan tim terbaik maju ke babak gugur sampai final.',
            'Perbaikan robot hanya dilakukan di area yang disediakan dan pada waktu jeda.',
        ],
        'seni' => [
            'Peserta melakukan daftar ulang dan mengambil nomor undian tampil.',
            'Setiap peserta tampil satu kali sesuai nomor undian dengan durasi paling lama 7 menit.',
            'Materi penampilan dipilih peserta sesuai tema dan ketentuan cabang lomba.',
            'Properti dan pengiring disiapkan sendiri oleh peserta. Panitia menyediakan panggung dan tata suara.',
            'Keputusan dewan juri bersifat mutlak dan tidak dapat diganggu gugat.',
        ],
        'film' => [
            'Peserta mengirim film pendek berdurasi 5-10 menit dalam format MP4 melalui laman pendaftaran.',
            'Film adalah karya asli peserta, dibuat dalam satu tahun terakhir, dan tidak mengandung unsur SARA, kekerasan, atau pornografi.',
            'Musik dan gambar yang dipakai harus milik sendiri atau berlisensi bebas.',
            'Dewan juri memilih nomine untuk diputar pada malam penganugerahan.',
            'Hak cipta film tetap pada peserta. Panitia berhak memutarnya untuk keperluan kegiatan.',
        ],
        'debat' => [
            'Lomba memakai sistem debat parlementer dengan tiga pembicara tiap tim.',
            'Babak penyisihan berlangsung tiga ronde. Mosi diumumkan 30 menit sebelum ronde dimulai.',
            'Delapan tim dengan nilai tertinggi maju ke babak gugur.',
            'Selama persiapan, tim hanya memakai bahan cetak. Alat komunikasi tidak diperkenankan.',
            'Keputusan dewan juri bersifat mutlak dan tidak dapat diganggu gugat.',
        ],
        'olahraga' => [
            'Peserta melakukan daftar ulang dan pemeriksaan kelengkapan sebelum pertandingan.',
            'Pertandingan memakai peraturan induk organisasi cabang olahraga yang berlaku.',
            'Babak penyisihan memakai sistem gugur. Undian dilakukan pada pertemuan teknis.',
            'Peserta wajib memakai perlengkapan bertanding yang sesuai ketentuan.',
            'Protes diajukan secara tertulis oleh guru pendamping paling lambat 15 menit setelah pertandingan selesai.',
        ],
        'beladiri' => [
            'Peserta mengikuti penimbangan badan dan pemeriksaan kesehatan sebelum bertanding.',
            'Pertandingan memakai peraturan induk organisasi cabang olahraga yang berlaku.',
            'Pertandingan memakai sistem gugur. Undian dilakukan pada pertemuan teknis.',
            'Peserta wajib memakai pelindung badan dan perlengkapan bertanding sesuai ketentuan.',
            'Protes diajukan secara tertulis oleh guru pendamping paling lambat 15 menit setelah pertandingan selesai.',
        ],
        'umum' => [
            'Peserta melakukan daftar ulang sebelum kegiatan dimulai.',
            'Lomba dilaksanakan sesuai urutan yang ditetapkan panitia pada pertemuan teknis.',
            'Peserta menyiapkan sendiri perlengkapan yang diperlukan.',
            'Keputusan dewan juri bersifat mutlak dan tidak dapat diganggu gugat.',
        ],
    ];

    /** flavor => [title of the section, table head, rows] */
    private const CRITERIA = [
        'olimpiade' => ['Kriteria Penilaian', ['No', 'Komponen', 'Bobot'], [['1', 'Tes pilihan ganda (penyisihan)', '40%'], ['2', 'Soal uraian (final)', '40%'], ['3', 'Pemecahan masalah (final)', '20%']]],
        'riset' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Orisinalitas dan kebaruan gagasan', '25%'], ['2', 'Metode penelitian', '25%'], ['3', 'Hasil dan pembahasan', '20%'], ['4', 'Manfaat dan peluang penerapan', '15%'], ['5', 'Presentasi dan tanya jawab', '15%']]],
        'robot' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Ketepatan menyelesaikan misi', '40%'], ['2', 'Waktu tempuh', '30%'], ['3', 'Rancangan dan kerapian robot', '20%'], ['4', 'Kerja sama tim', '10%']]],
        'seni' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Teknik dan penguasaan materi', '35%'], ['2', 'Penghayatan dan ekspresi', '30%'], ['3', 'Kreativitas', '20%'], ['4', 'Penampilan', '15%']]],
        'film' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Cerita dan pesan', '35%'], ['2', 'Penyutradaraan', '25%'], ['3', 'Sinematografi dan suara', '25%'], ['4', 'Kesesuaian dengan tema', '15%']]],
        'debat' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Isi argumen', '40%'], ['2', 'Cara penyampaian', '30%'], ['3', 'Strategi dan kerja sama tim', '30%']]],
        'olahraga' => ['Penentuan Pemenang', ['No', 'Babak', 'Ketentuan'], [['1', 'Penyisihan', 'Sistem gugur; pemenang maju ke babak berikutnya'], ['2', 'Semifinal', 'Pemenang maju ke final; yang kalah meraih peringkat III bersama'], ['3', 'Final', 'Pemenang meraih Juara I, lawannya Juara II']]],
        'beladiri' => ['Penentuan Pemenang', ['No', 'Babak', 'Ketentuan'], [['1', 'Penyisihan', 'Sistem gugur; pemenang maju ke babak berikutnya'], ['2', 'Semifinal', 'Pemenang maju ke final; yang kalah meraih peringkat III bersama'], ['3', 'Final', 'Pemenang meraih Juara I, lawannya Juara II']]],
        'umum' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Penguasaan materi', '40%'], ['2', 'Kreativitas', '30%'], ['3', 'Penampilan', '30%']]],
    ];

    public static function juknis(DemoEvent $event): DemoDocument
    {
        $flavor = self::flavor($event);
        $c = self::palette($event);
        $name = self::clip($event->name);
        $organizer = self::clip($event->organizer);
        $sport = in_array($flavor, ['olahraga', 'beladiri'], true);
        $where = $event->online ? 'secara daring' : 'di '.self::clip($event->location ?? 'tempat yang ditentukan panitia', 70);
        $signer = DemoPeople::signers($name, ['Ketua Panitia', 'Sekretaris Panitia'])[0];
        $city = self::city($event);
        $signedOn = $event->opensOn->subDays(7)->locale('id')->translatedFormat('j F Y');
        [$criteriaTitle, $criteriaHead, $criteriaRows] = self::CRITERIA[$flavor];

        return new DemoDocument(
            kind: DemoDocumentKind::Juknis,
            view: 'juknis',
            data: [
                'c' => $c,
                'name' => $name,
                'organizer' => $organizer,
                'year' => (string) $event->startsOn->year,
                'sections' => [
                    ['Latar Belakang', [
                        ['p', sprintf('%s menyelenggarakan %s sebagai wadah bagi siswa untuk %s. Kegiatan ini terbuka bagi siswa SMA/MA/SMK sederajat dan dilaksanakan %s.', $organizer, $name, self::PURPOSES[$flavor], $where)],
                        ['p', 'Petunjuk teknis ini memuat ketentuan peserta, jadwal, mekanisme lomba, '.mb_strtolower($criteriaTitle).', dan penghargaan. Seluruh peserta, guru pendamping, dan panitia wajib berpedoman pada petunjuk teknis ini. Hal yang belum diatur akan disampaikan pada pertemuan teknis.'],
                    ]],
                    ['Tujuan', [
                        ['ol', [
                            'Memberi ruang bagi siswa untuk '.self::PURPOSES[$flavor].'.',
                            'Menjaring peserta terbaik'.($event->level !== null ? ' '.DemoCertificate::levelPhrase($event->level) : '').' untuk dibina lebih lanjut.',
                            'Mempererat hubungan antarsekolah melalui kompetisi yang sehat dan sportif.',
                        ]],
                    ]],
                    ['Ketentuan Peserta', [
                        ['ol', [
                            'Peserta adalah siswa aktif SMA/MA/SMK sederajat pada tahun ajaran berjalan, dibuktikan dengan kartu pelajar atau surat keterangan dari sekolah.',
                            self::participation($event).'.',
                            'Peserta didaftarkan oleh sekolah dan membawa surat tugas dari kepala sekolah.',
                            'Setiap sekolah menunjuk satu guru pendamping yang bertanggung jawab atas pesertanya.',
                            'Data peserta yang sudah dikirim tidak dapat diganti setelah pendaftaran ditutup, kecuali atas persetujuan panitia.',
                            'Ketentuan biaya pendaftaran, bila ada, diumumkan di laman pendaftaran.',
                        ]],
                    ]],
                    ['Jadwal Kegiatan', [
                        ['table', ['head' => ['No', 'Kegiatan', 'Waktu', 'Keterangan'], 'rows' => self::schedule($event), 'widths' => [8, 30, 32, 30]]],
                    ]],
                    ['Mekanisme Lomba', [
                        ['ol', self::MECHANICS[$flavor]],
                    ]],
                    [$criteriaTitle, [
                        ['table', ['head' => $criteriaHead, 'rows' => $criteriaRows, 'widths' => $sport ? [8, 24, 68] : [8, 72, 20]]],
                        ['p', $sport
                            ? 'Hal teknis lain, seperti lama pertandingan dan cara pemberian nilai, mengikuti peraturan pertandingan yang berlaku dan disampaikan pada pertemuan teknis.'
                            : 'Nilai akhir adalah jumlah nilai tiap komponen setelah dikalikan bobotnya. Bila nilai akhir sama, pemenang ditentukan dari nilai pada komponen dengan bobot terbesar.'],
                    ]],
                    ['Penghargaan', [
                        ['ol', self::awards($event, $sport)],
                    ]],
                    ['Tata Tertib', [
                        ['ol', [
                            $event->online
                                ? 'Peserta bergabung ke ruang daring paling lambat 15 menit sebelum kegiatan dimulai dengan nama sesuai data pendaftaran.'
                                : 'Peserta hadir paling lambat 30 menit sebelum kegiatan dimulai dan memakai seragam sekolah atau kostum yang ditentukan.',
                            'Peserta menjaga ketertiban, kebersihan, dan sportivitas selama kegiatan.',
                            'Segala bentuk kecurangan berakibat pembatalan keikutsertaan.',
                            'Panitia tidak bertanggung jawab atas kehilangan barang pribadi peserta.',
                        ]],
                    ]],
                    ['Narahubung', [
                        ['p', 'Pertanyaan tentang kegiatan ini dapat disampaikan kepada Sekretariat Panitia '.$name.':'],
                        ['ul', ['Email: '.self::EMAIL, 'Laman: '.self::site($event), 'Telepon: '.self::PHONE]],
                    ]],
                    ['Penutup', [
                        ['p', 'Demikian petunjuk teknis ini disusun sebagai pedoman pelaksanaan '.$name.'. Perubahan atas petunjuk teknis ini, bila ada, diumumkan melalui laman kegiatan.'],
                    ]],
                ],
                'placeDate' => $city === null ? $signedOn : $city.', '.$signedOn,
                'signer' => $signer,
            ],
            images: [
                'lambang.svg' => DemoArt::emblem(self::EMBLEMS[$flavor], ['primary' => '#ffffff', 'accent' => $c['accent'], 'dark' => $c['soft'], 'soft' => '#ffffff']),
                'ttd.svg' => DemoArt::penStroke($signer['name']),
                'stempel.svg' => DemoArt::seal('#3b3f9c'),
            ],
            paging: DemoDocument::PAGING_PLAIN,
            title: 'Petunjuk teknis - '.$name,
        );
    }

    public static function brosur(DemoEvent $event): DemoDocument
    {
        return self::flyer($event, DemoDocumentKind::Brosur);
    }

    public static function poster(DemoEvent $event): DemoDocument
    {
        return self::flyer($event, DemoDocumentKind::Poster);
    }

    /** A plain formal page for a demo file that fits no other kind. */
    public static function umum(string $title, string $purpose, ?string $owner = null): DemoDocument
    {
        return new DemoDocument(
            kind: DemoDocumentKind::Umum,
            view: 'umum',
            data: [
                'title' => self::clip($title, 120),
                'purpose' => $purpose,
                'owner' => $owner,
            ],
            images: ['lambang.svg' => DemoArt::emblem('buku', ['primary' => '#1f3a5f', 'accent' => '#b8891a', 'dark' => '#14284b', 'soft' => '#dfe6f1'])],
            title: 'Dokumen contoh - '.self::clip($title, 120),
        );
    }

    private static function flyer(DemoEvent $event, DemoDocumentKind $kind): DemoDocument
    {
        $flavor = self::flavor($event);
        $c = self::palette($event);
        $name = self::clip($event->name, 90);
        $poster = $kind === DemoDocumentKind::Poster;

        return new DemoDocument(
            kind: $kind,
            view: 'brosur',
            data: [
                'style' => $poster ? 'poster' : 'brosur',
                'c' => $c,
                'name' => $name,
                'nameSize' => mb_strlen($name) > 46 ? 24 : (mb_strlen($name) > 30 ? 29 : 34),
                'organizer' => self::clip($event->organizer, 70),
                'field' => $event->bidang?->label() ?? 'Umum',
                'tagline' => self::TAGLINES[$flavor],
                'facts' => [
                    ['Pendaftaran', self::range($event->opensOn, $event->closesOn)],
                    ['Pelaksanaan', self::range($event->startsOn, $event->finalOn)],
                    ['Tempat', $event->online ? 'Daring' : self::clip($event->location ?? 'Diumumkan panitia', 40)],
                ],
                'categories' => self::CATEGORIES[$flavor],
                'who' => [
                    'Siswa aktif SMA/MA/SMK sederajat',
                    self::participation($event),
                    'Didaftarkan oleh sekolah dengan surat tugas kepala sekolah',
                ],
                'prizes' => [
                    'Trofi dan sertifikat untuk Juara I, II, dan III',
                    'Dana pembinaan untuk para juara',
                    'Sertifikat elektronik untuk semua peserta',
                ],
                'steps' => [
                    'Buka laman pendaftaran dan isi data peserta',
                    'Upload berkas yang diminta',
                    'Tunggu konfirmasi dari panitia lewat email',
                ],
                'place' => $event->online ? 'Daring' : ($event->location !== null ? self::clip($event->location, 40) : null),
                'deadline' => $event->closesOn->locale('id')->translatedFormat('j F Y'),
                'site' => self::site($event),
                'email' => self::EMAIL,
            ],
            images: [
                'bg.svg' => DemoArt::flyerBackground($poster ? 'poster' : 'brosur', $c),
                'lambang.svg' => DemoArt::emblem(self::EMBLEMS[$flavor], ['primary' => '#ffffff', 'accent' => $c['accent'], 'dark' => $c['soft'], 'soft' => '#ffffff']),
            ],
            title: ($poster ? 'Poster - ' : 'Brosur - ').$name,
        );
    }

    // Content helpers ----------------------------------------------------------------------------------

    /** olimpiade | riset | robot | seni | film | debat | olahraga | beladiri | umum */
    public static function flavor(DemoEvent $event): string
    {
        $name = mb_strtolower($event->name);

        return match (true) {
            str_contains($name, 'debat') || str_contains($name, 'ldbi') => 'debat',
            str_contains($name, 'film') => 'film',
            str_contains($name, 'robot') => 'robot',
            str_contains($name, 'silat') || str_contains($name, 'karate') || str_contains($name, 'taekwondo') => 'beladiri',
            $event->bidang === Bidang::Sains => 'olimpiade',
            $event->bidang === Bidang::Riset => 'riset',
            $event->bidang === Bidang::SeniBudaya => 'seni',
            $event->bidang === Bidang::Olahraga => 'olahraga',
            default => 'umum',
        };
    }

    /**
     * Registration, technical meeting, the lomba and the announcement, from the lomba's own dates.
     *
     * @return list<list<string>>
     */
    public static function schedule(DemoEvent $event): array
    {
        $meeting = $event->closesOn->addDays(3);

        if ($meeting->gte($event->startsOn)) {
            $meeting = $event->startsOn->subDay();
        }

        if ($meeting->lt($event->closesOn)) {
            $meeting = $event->closesOn;
        }

        $announcement = $event->online ? $event->finalOn->addDays(7) : $event->finalOn;
        $place = $event->online ? 'Daring' : self::clip($event->location ?? 'Diumumkan panitia', 60);

        $rows = [
            ['Pendaftaran dan pengiriman berkas', self::range($event->opensOn, $event->closesOn), 'Melalui laman pendaftaran'],
            ['Pertemuan teknis', $meeting->locale('id')->translatedFormat('j F Y'), 'Daring, link dikirim lewat email'],
            ['Pelaksanaan lomba', self::range($event->startsOn, $event->finalOn), $place],
            ['Pengumuman pemenang', $announcement->locale('id')->translatedFormat('j F Y'), $event->online ? 'Laman kegiatan' : 'Saat penutupan kegiatan'],
        ];

        return array_map(fn (array $row, int $i): array => [(string) ($i + 1), ...$row], $rows, array_keys($rows));
    }

    /** "1 September s.d. 10 Oktober 2026" (one date when both are the same day). */
    public static function range(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $last = $to->locale('id')->translatedFormat('j F Y');

        return match (true) {
            $from->isSameDay($to) => $last,
            $from->isSameMonth($to) => $from->format('j').' s.d. '.$last,
            $from->isSameYear($to) => $from->locale('id')->translatedFormat('j F').' s.d. '.$last,
            default => $from->locale('id')->translatedFormat('j F Y').' s.d. '.$last,
        };
    }

    private static function participation(DemoEvent $event): string
    {
        $max = max(2, (int) ($event->maxTeam ?? 3));

        return match (true) {
            $event->team && $event->individual => 'Lomba dapat diikuti perorangan atau tim (paling banyak '.$max.' siswa dari sekolah yang sama)',
            $event->team => 'Lomba diikuti secara tim, paling banyak '.$max.' siswa dari sekolah yang sama',
            default => 'Lomba diikuti secara perorangan',
        };
    }

    /** @return list<string> */
    private static function awards(DemoEvent $event, bool $sport): array
    {
        $staged = in_array($event->level, [Level::Sekolah, Level::Kota, Level::Provinsi], true)
            && in_array($event->organizerType, [OrganizerType::PemerintahPusat, OrganizerType::PemerintahDaerah], true);

        return [
            $sport
                ? 'Juara I, II, dan III memperoleh medali, piagam penghargaan, dan dana pembinaan.'
                : 'Juara I, II, dan III memperoleh trofi, sertifikat, dan dana pembinaan.',
            $sport
                ? 'Peraih peringkat III bersama memperoleh medali dan piagam penghargaan.'
                : 'Finalis memperoleh sertifikat finalis.',
            'Seluruh peserta dan guru pendamping memperoleh sertifikat elektronik.',
            $staged
                ? 'Juara I berhak diusulkan mengikuti jenjang berikutnya sesuai ketentuan penyelenggara.'
                : 'Pemenang diumumkan melalui laman kegiatan.',
        ];
    }

    /** Always an example.test address, whatever website the lomba row carries. */
    private static function site(DemoEvent $event): string
    {
        $slug = '';

        // Whole words of the name, up to about 46 characters.
        foreach (array_filter(explode('-', Str::slug($event->name))) as $word) {
            if ($slug !== '' && strlen($slug) + strlen($word) + 1 > 46) {
                break;
            }

            $slug .= ($slug === '' ? '' : '-').Str::limit($word, 46, '');
        }

        return 'https://example.test/'.($slug !== '' ? $slug : 'lomba');
    }

    private static function city(DemoEvent $event): ?string
    {
        return DemoCertificate::city(new DemoAward(
            recipient: DemoPeople::FALLBACK_STUDENT['name'],
            competition: $event->name,
            organizer: $event->organizer,
            result: ResultType::Peserta,
            location: $event->online ? null : $event->location,
        ));
    }

    /** @return array{primary: string, accent: string, dark: string, soft: string} */
    private static function palette(DemoEvent $event): array
    {
        $colors = self::PALETTES[$event->bidang?->value ?? 'sains'] ?? self::PALETTES['sains'];

        return ['primary' => $colors[0], 'accent' => $colors[1], 'dark' => $colors[2], 'soft' => $colors[3]];
    }

    private static function clip(string $text, int $max = 110): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), $max, '…');
    }
}
