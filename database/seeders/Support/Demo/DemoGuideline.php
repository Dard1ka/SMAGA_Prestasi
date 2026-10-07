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
 * The wording is ours and follows the kind of lomba (olimpiade, riset, inovasi, robotik, seni, film, debat, olahraga,
 * bela diri), which is read from the bidang and the name. Since QA5 the rules for participants, the conduct, the
 * schedule labels and the awards follow the kind too, so two juknis of one bidang no longer read alike. The schedule is built from the lomba's own dates. Contact
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
        'olimpiade' => 'atom', 'riset' => 'lampu', 'inovasi' => 'atom', 'robot' => 'lampu', 'seni' => 'bunga',
        'film' => 'bunga', 'debat' => 'buku', 'olahraga' => 'obor', 'beladiri' => 'perisai', 'umum' => 'perisai',
    ];

    private const PURPOSES = [
        'olimpiade' => 'mengukur kemampuan, menumbuhkan budaya belajar, dan menjaring bibit unggul di bidang sains',
        'riset' => 'menuangkan gagasan, meneliti masalah di sekitarnya, dan menyampaikan hasilnya secara ilmiah',
        'inovasi' => 'mewujudkan gagasan menjadi purwarupa atau produk yang berguna dan memperagakannya di depan juri dan pengunjung',
        'robot' => 'merancang, membuat, dan memprogram robot untuk menyelesaikan tantangan yang diberikan',
        'seni' => 'menampilkan karya terbaik, mengasah kreativitas, dan menumbuhkan kecintaan pada seni dan budaya',
        'film' => 'bercerita melalui film pendek dan mengasah kemampuan membuat karya audio-visual',
        'debat' => 'berpikir kritis, menyusun argumen, dan menyampaikan pendapat secara santun',
        'olahraga' => 'bertanding secara sportif, mengukur hasil latihan, dan menjaring atlet pelajar berprestasi',
        'beladiri' => 'menguji teknik dan mental bertanding dalam bela diri, menjunjung sportivitas, dan menjaring pesilat dan atlet bela diri pelajar',
        'umum' => 'menunjukkan kemampuan terbaiknya dan belajar dari peserta lain',
    ];

    private const TAGLINES = [
        'olimpiade' => 'Uji kemampuanmu, raih prestasi terbaikmu.',
        'riset' => 'Dari rasa ingin tahu menjadi karya yang bermanfaat.',
        'inovasi' => 'Dari gagasan menjadi karya yang dipamerkan.',
        'robot' => 'Rancang, rakit, dan taklukkan tantangannya.',
        'seni' => 'Panggung untuk karya dan kreativitasmu.',
        'film' => 'Ceritakan duniamu dalam satu film pendek.',
        'debat' => 'Sampaikan gagasanmu dengan argumen terbaik.',
        'olahraga' => 'Bertanding sportif, raih prestasi tertinggi.',
        'beladiri' => 'Teknik terlatih, mental juara, sportif sampai akhir.',
        'umum' => 'Tunjukkan kemampuan terbaikmu.',
    ];

    private const CATEGORIES = [
        'olimpiade' => ['Matematika', 'Fisika, Kimia, dan Biologi', 'Informatika dan Astronomi'],
        'riset' => ['Sains dan teknologi', 'Lingkungan dan energi', 'Sosial humaniora'],
        'inovasi' => ['Teknologi terapan', 'Lingkungan dan energi', 'Kesehatan dan pangan'],
        'robot' => ['Robot pengikut garis', 'Robot pemindah barang', 'Robot kreatif'],
        'seni' => ['Penampilan tunggal', 'Penampilan kelompok', 'Penampilan favorit penonton'],
        'film' => ['Film fiksi pendek', 'Film dokumenter pendek', 'Poster film terbaik'],
        'debat' => ['Tim terbaik', 'Pembicara terbaik', 'Tim favorit'],
        'olahraga' => ['Putra', 'Putri', 'Beregu'],
        'beladiri' => ['Kategori tanding putra', 'Kategori tanding putri', 'Kategori seni'],
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
        'inovasi' => [
            'Peserta mengirim abstrak karya, foto purwarupa, dan video peragaan paling lama 3 menit sebelum pendaftaran ditutup.',
            'Dewan juri menilai berkas dan menetapkan karya yang lolos ke babak pameran.',
            'Setiap karya yang lolos mendapat satu stan pameran berukuran 2 x 2 meter. Peserta menata stan sendiri dan membawa purwarupa yang dapat diperagakan.',
            'Penjurian dilakukan di stan pameran: peserta memperagakan karya dan menjawab pertanyaan juri selama 15 menit.',
            'Medali emas, perak, dan perunggu diberikan menurut nilai karya, bukan peringkat, sehingga satu kategori dapat memiliki lebih dari satu peraih medali.',
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
            'Lomba terdiri atas kategori tanding, dibagi per kelas berat badan putra dan putri, dan kategori seni (tunggal, ganda, dan regu).',
            'Peserta kategori tanding mengikuti penimbangan badan sehari sebelum bertanding. Peserta yang beratnya tidak sesuai kelasnya dinyatakan gugur.',
            'Kategori tanding memakai sistem gugur, tiga babak tiap partai. Undian bagan dilakukan pada pertemuan teknis.',
            'Kategori seni dinilai lima juri dari kebenaran gerak, kemantapan, dan penghayatan.',
            'Peserta membawa sendiri pakaian bela diri dan pelindung badan sesuai peraturan induk organisasi.',
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
        'inovasi' => ['Penilaian Karya', ['No', 'Unsur', 'Bobot'], [['1', 'Kebaruan karya dibanding produk yang sudah ada', '30%'], ['2', 'Fungsi dan unjuk kerja purwarupa', '25%'], ['3', 'Manfaat bagi masyarakat dan peluang usaha', '20%'], ['4', 'Tata stan dan peragaan', '15%'], ['5', 'Penguasaan karya saat tanya jawab', '10%']]],
        'robot' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Ketepatan menyelesaikan misi', '40%'], ['2', 'Waktu tempuh', '30%'], ['3', 'Rancangan dan kerapian robot', '20%'], ['4', 'Kerja sama tim', '10%']]],
        'seni' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Teknik dan penguasaan materi', '35%'], ['2', 'Penghayatan dan ekspresi', '30%'], ['3', 'Kreativitas', '20%'], ['4', 'Penampilan', '15%']]],
        'film' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Cerita dan pesan', '35%'], ['2', 'Penyutradaraan', '25%'], ['3', 'Sinematografi dan suara', '25%'], ['4', 'Kesesuaian dengan tema', '15%']]],
        'debat' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Isi argumen', '40%'], ['2', 'Cara penyampaian', '30%'], ['3', 'Strategi dan kerja sama tim', '30%']]],
        'olahraga' => ['Penentuan Pemenang', ['No', 'Babak', 'Ketentuan'], [['1', 'Penyisihan', 'Sistem gugur; pemenang maju ke babak berikutnya'], ['2', 'Semifinal', 'Pemenang maju ke final; yang kalah meraih peringkat III bersama'], ['3', 'Final', 'Pemenang meraih Juara I, lawannya Juara II']]],
        'beladiri' => ['Penentuan Juara', ['No', 'Kategori', 'Ketentuan'], [['1', 'Kategori tanding', 'Per kelas berat badan: pemenang final Juara I, lawannya Juara II, kedua semifinalis yang kalah Juara III bersama'], ['2', 'Kategori seni', 'Nilai tertinggi dari lima juri; bila sama, penampilan dengan waktu paling tepat yang menang'], ['3', 'Juara umum', 'Kontingen dengan medali emas terbanyak']]],
        'umum' => ['Kriteria Penilaian', ['No', 'Aspek', 'Bobot'], [['1', 'Penguasaan materi', '40%'], ['2', 'Kreativitas', '30%'], ['3', 'Penampilan', '30%']]],
    ];

    /** flavor => three rules for participants of this kind of lomba (after the three that hold everywhere) */
    private const RULES = [
        'olimpiade' => [
            'Setiap peserta hanya boleh mengikuti satu bidang lomba.',
            'Peserta belum pernah meraih medali emas olimpiade tingkat internasional di bidang yang sama.',
            'Peserta membawa kartu peserta yang dicetak dari laman pendaftaran pada hari tes.',
        ],
        'riset' => [
            'Satu tim mengirim paling banyak dua karya, dan setiap siswa hanya tercatat pada satu tim.',
            'Karya dibimbing satu guru pembimbing yang namanya tercantum pada naskah.',
            'Naskah memakai format penulisan dari panitia, paling banyak 20 halaman tanpa lampiran.',
        ],
        'inovasi' => [
            'Satu karya dikerjakan paling banyak oleh tiga siswa dan didampingi satu guru pembimbing.',
            'Karya berupa purwarupa atau produk yang dapat diperagakan, bukan hanya gagasan di atas kertas.',
            'Peserta bertanggung jawab atas keamanan purwarupa selama pameran, termasuk bahan dan sumber listriknya.',
        ],
        'robot' => [
            'Setiap tim terdiri atas dua sampai tiga siswa dan satu guru pendamping.',
            'Robot tidak boleh merusak lintasan atau membahayakan peserta lain.',
            'Komponen robot dibawa sendiri; panitia hanya menyediakan sumber listrik di area tim.',
        ],
        'seni' => [
            'Setiap sekolah mengirim paling banyak satu peserta atau satu kelompok untuk tiap cabang.',
            'Materi penampilan belum pernah dibawakan pada lomba tingkat yang sama tahun ini.',
            'Peserta mengirim judul materi dan rekaman pengiring, bila ada, sebelum pendaftaran ditutup.',
        ],
        'film' => [
            'Satu tim produksi beranggotakan paling banyak lima siswa dari sekolah yang sama.',
            'Setiap tim hanya boleh mengirim satu film.',
            'Film memakai teks terjemahan bahasa Indonesia bila dialognya berbahasa daerah atau asing.',
        ],
        'debat' => [
            'Setiap tim terdiri atas tiga pembicara dan boleh membawa satu cadangan.',
            'Setiap sekolah mengirim paling banyak dua tim.',
            'Debat memakai bahasa Indonesia yang baik dan benar.',
        ],
        'olahraga' => [
            'Peserta berusia paling tinggi 18 tahun pada hari pertama pertandingan, dibuktikan dengan akta kelahiran.',
            'Peserta dalam keadaan sehat, dibuktikan dengan surat keterangan dokter.',
            'Setiap sekolah mengirim paling banyak satu peserta atau satu regu untuk tiap nomor.',
        ],
        'beladiri' => [
            'Peserta terdaftar sebagai anggota perguruan atau klub yang diakui induk organisasi bela diri.',
            'Peserta kategori tanding hanya boleh turun di satu kelas berat badan.',
            'Peserta melampirkan surat keterangan sehat dari dokter dan surat izin orang tua.',
        ],
        'umum' => [
            'Setiap sekolah menunjuk satu guru pendamping yang bertanggung jawab atas pesertanya.',
            'Data peserta yang sudah dikirim tidak dapat diganti setelah pendaftaran ditutup, kecuali atas persetujuan panitia.',
            'Ketentuan biaya pendaftaran, bila ada, diumumkan di laman pendaftaran.',
        ],
    ];

    /** flavor => three conduct rules (after the one about arriving or joining on time) */
    private const CONDUCT = [
        'olimpiade' => [
            'Peserta duduk sesuai nomor peserta dan tidak meninggalkan ruang tes pada 30 menit pertama.',
            'Bekerja sama, menyontek, atau membawa catatan ke ruang tes berakibat diskualifikasi.',
            'Lembar soal dan lembar jawaban diserahkan kepada pengawas saat waktu habis.',
        ],
        'riset' => [
            'Finalis hadir bersama seluruh anggota tim saat presentasi.',
            'Karya yang terbukti menjiplak atau memakai data palsu dibatalkan, juga bila sudah diumumkan sebagai juara.',
            'Poster ilmiah dipasang di tempat yang ditentukan panitia paling lambat satu jam sebelum presentasi.',
        ],
        'inovasi' => [
            'Peserta menjaga stan pameran selama jam pameran dan tidak meninggalkan purwarupa tanpa penjaga.',
            'Bahan mudah terbakar, bahan kimia berbahaya, dan hewan hidup tidak boleh dibawa ke area pameran.',
            'Stan dibongkar dan dibersihkan oleh peserta setelah penutupan.',
        ],
        'robot' => [
            'Hanya anggota tim yang terdaftar yang boleh masuk area perbaikan robot.',
            'Robot yang melanggar ukuran atau berat yang ditetapkan tidak boleh bertanding.',
            'Tim yang tidak hadir setelah dipanggil tiga kali dinyatakan gugur pada ronde itu.',
        ],
        'seni' => [
            'Peserta yang tidak hadir saat nomor undiannya dipanggil tampil di urutan terakhir.',
            'Penonton tidak memotret dengan lampu kilat selama penampilan berlangsung.',
            'Peserta merapikan properti dari panggung segera setelah tampil.',
        ],
        'film' => [
            'Film yang terbukti memakai karya orang lain tanpa izin didiskualifikasi.',
            'Nomine hadir pada malam penganugerahan atau mengirim wakil timnya.',
            'Film tidak diunggah ke media sosial sebelum pemenang diumumkan.',
        ],
        'debat' => [
            'Peserta menghormati lawan dan tidak menyerang pribadi pembicara.',
            'Pembicara tidak menerima bantuan dari pelatih atau penonton selama ronde berlangsung.',
            'Tim yang terlambat lebih dari 10 menit dinyatakan kalah pada ronde itu.',
        ],
        'olahraga' => [
            'Peserta memakai nomor punggung yang dibagikan panitia selama pertandingan.',
            'Peserta yang tidak hadir 15 menit setelah dipanggil dinyatakan kalah walk out.',
            'Tindakan tidak sportif terhadap lawan, wasit, atau panitia berakibat diskualifikasi.',
        ],
        'beladiri' => [
            'Peserta memotong kuku dan tidak memakai perhiasan atau benda keras selama bertanding.',
            'Peserta yang tidak hadir di gelanggang setelah tiga kali dipanggil dinyatakan kalah.',
            'Pelatih memberi arahan hanya dari sudut yang ditentukan dan pada waktu istirahat.',
        ],
        'umum' => [
            'Peserta menjaga ketertiban, kebersihan, dan sportivitas selama kegiatan.',
            'Segala bentuk kecurangan berakibat pembatalan keikutsertaan.',
            'Panitia tidak bertanggung jawab atas kehilangan barang pribadi peserta.',
        ],
    ];

    /** flavor => names of the four schedule rows (registration, meeting, the lomba, announcement) */
    private const STAGES = [
        'olimpiade' => ['Pendaftaran peserta', 'Pengiriman kartu peserta dan tata cara tes', 'Tes penyisihan dan final', 'Pengumuman pemenang'],
        'riset' => ['Pendaftaran dan pengiriman naskah', 'Pengumuman finalis dan pertemuan teknis', 'Presentasi finalis', 'Pengumuman pemenang'],
        'inovasi' => ['Pendaftaran, abstrak, dan video purwarupa', 'Pengumuman karya yang lolos pameran', 'Pameran dan penjurian di stan', 'Pengumuman peraih medali'],
        'robot' => ['Pendaftaran tim', 'Pertemuan teknis dan pemeriksaan robot', 'Pertandingan robot', 'Pengumuman pemenang'],
        'seni' => ['Pendaftaran dan pengiriman judul materi', 'Pertemuan teknis dan undian tampil', 'Penampilan peserta', 'Pengumuman pemenang'],
        'film' => ['Pendaftaran dan pengiriman film', 'Pengumuman nomine', 'Pemutaran film nomine', 'Malam penganugerahan'],
        'debat' => ['Pendaftaran tim', 'Pertemuan teknis dan pembagian ruang', 'Ronde penyisihan sampai final', 'Pengumuman pemenang'],
        'olahraga' => ['Pendaftaran dan berkas atlet', 'Pertemuan teknis dan undian', 'Pertandingan', 'Penyerahan medali'],
        'beladiri' => ['Pendaftaran dan berkas atlet', 'Pertemuan teknis, undian, dan penimbangan', 'Pertandingan kategori tanding dan seni', 'Penyerahan medali'],
        'umum' => ['Pendaftaran dan pengiriman berkas', 'Pertemuan teknis', 'Pelaksanaan lomba', 'Pengumuman pemenang'],
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
                            ...self::RULES[$flavor],
                        ]],
                    ]],
                    ['Jadwal Kegiatan', [
                        ['table', ['head' => ['No', 'Kegiatan', 'Waktu', 'Keterangan'], 'rows' => self::schedule($event, self::STAGES[$flavor]), 'widths' => [8, 30, 32, 30]]],
                    ]],
                    ['Mekanisme Lomba', [
                        ['ol', self::MECHANICS[$flavor]],
                    ]],
                    [$criteriaTitle, [
                        ['table', ['head' => $criteriaHead, 'rows' => $criteriaRows, 'widths' => $sport ? [8, 24, 68] : [8, 72, 20]]],
                        ['p', match (true) {
                            $flavor === 'beladiri' => 'Lama babak, cara pemberian nilai, dan pelanggaran mengikuti peraturan pertandingan induk organisasi bela diri yang bersangkutan.',
                            $sport => 'Hal teknis lain, seperti lama pertandingan dan cara pemberian nilai, mengikuti peraturan pertandingan yang berlaku dan disampaikan pada pertemuan teknis.',
                            $flavor === 'inovasi' => 'Karya dengan nilai akhir 85 ke atas meraih medali emas, 75 sampai 84 medali perak, dan 65 sampai 74 medali perunggu.',
                            default => 'Nilai akhir adalah jumlah nilai tiap komponen setelah dikalikan bobotnya. Bila nilai akhir sama, pemenang ditentukan dari nilai pada komponen dengan bobot terbesar.',
                        }],
                    ]],
                    ['Penghargaan', [
                        ['ol', self::awards($event, $flavor)],
                    ]],
                    ['Tata Tertib', [
                        ['ol', [
                            $event->online
                                ? 'Peserta bergabung ke ruang daring paling lambat 15 menit sebelum kegiatan dimulai dengan nama sesuai data pendaftaran.'
                                : 'Peserta hadir paling lambat 30 menit sebelum kegiatan dimulai dan memakai seragam sekolah atau kostum yang ditentukan.',
                            ...self::CONDUCT[$flavor],
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

    /** Words in a lomba name that mark an invention fair (inovasi), not a research paper (riset). */
    private const INVENTION_WORDS = ['invention', 'inventor', 'inovasi', 'krenova', 'fiksi', 'nsif', 'wice', 'wseec', 'expo', 'fair'];

    /** olimpiade | riset | inovasi | robot | seni | film | debat | olahraga | beladiri | umum */
    public static function flavor(DemoEvent $event): string
    {
        $name = mb_strtolower($event->name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            str_contains($name, 'debat') || str_contains($name, 'ldbi') => 'debat',
            str_contains($name, 'film') => 'film',
            str_contains($name, 'robot') => 'robot',
            str_contains($name, 'silat') || str_contains($name, 'karate') || str_contains($name, 'taekwondo') => 'beladiri',
            array_intersect($words, self::INVENTION_WORDS) !== [] => 'inovasi',
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
     * @param  list<string>|null  $stages  the four row names; null = the general ones
     * @return list<list<string>>
     */
    public static function schedule(DemoEvent $event, ?array $stages = null): array
    {
        [$register, $meet, $hold, $announce] = $stages ?? self::STAGES['umum'];
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
            [$register, self::range($event->opensOn, $event->closesOn), 'Melalui laman pendaftaran'],
            [$meet, $meeting->locale('id')->translatedFormat('j F Y'), 'Daring, link dikirim lewat email'],
            [$hold, self::range($event->startsOn, $event->finalOn), $place],
            [$announce, $announcement->locale('id')->translatedFormat('j F Y'), $event->online ? 'Laman kegiatan' : 'Saat penutupan kegiatan'],
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
    private static function awards(DemoEvent $event, string $flavor): array
    {
        $staged = in_array($event->level, [Level::Sekolah, Level::Kota, Level::Provinsi], true)
            && in_array($event->organizerType, [OrganizerType::PemerintahPusat, OrganizerType::PemerintahDaerah], true);

        [$winners, $runnersUp] = match ($flavor) {
            'inovasi' => [
                'Karya terbaik memperoleh medali emas, perak, atau perunggu sesuai nilainya, beserta sertifikat medali.',
                'Penghargaan khusus diberikan untuk stan terbaik dan karya pilihan pengunjung.',
            ],
            'beladiri' => [
                'Juara I, II, dan III setiap kelas tanding dan setiap nomor seni memperoleh medali dan piagam.',
                'Kontingen dengan medali emas terbanyak meraih piala juara umum.',
            ],
            'olahraga' => [
                'Juara I, II, dan III memperoleh medali, piagam penghargaan, dan dana pembinaan.',
                'Peraih peringkat III bersama memperoleh medali dan piagam penghargaan.',
            ],
            'film' => [
                'Film terbaik, sutradara terbaik, dan sinematografi terbaik memperoleh trofi dan sertifikat.',
                'Setiap nomine memperoleh sertifikat nomine dan filmnya diputar pada malam penganugerahan.',
            ],
            'debat' => [
                'Juara I, II, dan III memperoleh trofi, sertifikat, dan dana pembinaan.',
                'Pembicara terbaik babak penyisihan dan final memperoleh sertifikat penghargaan.',
            ],
            default => [
                'Juara I, II, dan III memperoleh trofi, sertifikat, dan dana pembinaan.',
                'Finalis memperoleh sertifikat finalis.',
            ],
        };

        return [
            $winners,
            $runnersUp,
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
