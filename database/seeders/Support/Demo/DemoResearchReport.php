<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A school research report ("laporan penelitian") for the demo data (LOCAL ONLY): cover, lembar pengesahan,
 * abstrak + kata kunci, kata pengantar, daftar isi, BAB I–V with a small data table, daftar pustaka.
 *
 * The text is written for this project. The report on bioplastic from banana peel matches the research title of
 * the demo LKTI report; every other title gets the general text of generic(). All numbers are sample data and
 * the tables say so. The references are invented on purpose (publishers and journals carry the word "Contoh");
 * no real book, article or DOI is cited.
 *
 * Block types of a section: ['p', text] · ['ol', list] · ['ul', list] ·
 * ['table', ['caption' => …, 'head' => […], 'rows' => [[…]], 'note' => …]].
 */
final class DemoResearchReport
{
    public const BIOPLASTIC_TITLE = 'Bioplastik dari limbah kulit pisang untuk kemasan jajanan sekolah';

    /**
     * @param  list<array{name: string, nis: string, class: string}>  $authors  fictional students of the demo set
     */
    public static function document(string $title, ?string $abstract, string $competition, array $authors, string $mentor, CarbonImmutable $date): DemoDocument
    {
        $title = trim($title) !== '' ? Str::limit(trim($title), 180, '…') : self::BIOPLASTIC_TITLE;
        $competition = Str::limit(trim($competition), 110, '…');
        $content = self::isBioplastic($title) ? self::bioplastic() : self::generic($title, $abstract);
        $c = ['primary' => '#1f3a5f', 'accent' => '#b8891a', 'dark' => '#14284b', 'soft' => '#dfe6f1'];
        $signers = [$mentor, $authors[0]['name'], DemoPeople::PRINCIPAL['name']];

        return new DemoDocument(
            kind: DemoDocumentKind::LaporanPenelitian,
            view: 'laporan-penelitian',
            data: [
                'title' => $title,
                'competition' => $competition,
                'authors' => $authors,
                'mentor' => $mentor,
                'principal' => DemoPeople::PRINCIPAL['name'],
                'school' => DemoPeople::SCHOOL,
                'city' => DemoPeople::SCHOOL_CITY,
                'year' => (string) $date->year,
                'dateText' => DemoPeople::SCHOOL_CITY.', '.$date->locale('id')->translatedFormat('j F Y'),
                'field' => $content['field'],
                'abstract' => $content['abstract'],
                'keywords' => $content['keywords'],
                'preface' => self::preface($title, $competition, $mentor),
                'chapters' => $content['chapters'],
                'references' => $content['references'],
            ],
            images: [
                'lambang.svg' => DemoArt::emblem('buku', $c),
                'ttd-1.svg' => DemoArt::penStroke($signers[0]),
                'ttd-2.svg' => DemoArt::penStroke($signers[1], '#1c1c1c'),
                'ttd-3.svg' => DemoArt::penStroke($signers[2]),
            ],
            paging: DemoDocument::PAGING_REPORT,
            title: 'Laporan penelitian - '.$title,
        );
    }

    public static function isBioplastic(string $title): bool
    {
        $title = mb_strtolower($title);

        return str_contains($title, 'bioplastik') && str_contains($title, 'pisang');
    }

    /** @return list<string> */
    private static function preface(string $title, string $competition, string $mentor): array
    {
        return [
            'Puji syukur penulis panjatkan ke hadirat Tuhan Yang Maha Esa karena laporan penelitian berjudul "'.$title.'" ini dapat diselesaikan tepat waktu. Laporan ini disusun untuk mengikuti '.$competition.'.',
            'Penelitian ini tidak akan selesai tanpa bantuan banyak pihak. Penulis mengucapkan terima kasih kepada Kepala '.DemoPeople::SCHOOL.' yang telah memberi izin dan dukungan, kepada '.$mentor.' selaku guru pembimbing yang dengan sabar mengarahkan penulis sejak perencanaan sampai penulisan laporan, serta kepada laboran, teman-teman, dan keluarga yang ikut membantu.',
            'Penulis menyadari bahwa laporan ini masih memiliki kekurangan, baik dari segi isi maupun cara penulisan. Kritik dan saran yang membangun sangat penulis harapkan untuk perbaikan penelitian berikutnya. Semoga laporan ini bermanfaat bagi pembaca.',
        ];
    }

    /**
     * @return array{field: string, abstract: list<string>, keywords: string, chapters: list<array<string, mixed>>, references: list<string>}
     */
    private static function bioplastic(): array
    {
        return [
            'field' => 'Kimia terapan dan lingkungan',
            'abstract' => [
                'Kemasan plastik sekali pakai masih banyak dipakai untuk membungkus jajanan di kantin sekolah dan sulit terurai di alam. Penelitian ini bertujuan membuat bioplastik dari pati limbah kulit pisang kepok serta mengetahui pengaruh jumlah gliserol terhadap kuat tarik dan waktu urainya. Bioplastik dibuat dengan cara pencetakan larutan pada tiga komposisi gliserol, yaitu 1 mL, 2 mL, dan 3 mL untuk setiap 10 g pati, masing-masing dengan tiga kali ulangan. Kuat tarik diukur dengan alat uji tarik sederhana berbeban, sedangkan waktu urai diamati dengan mengubur sampel berukuran 3 cm × 3 cm di dalam tanah kebun sekolah.',
                'Hasil penelitian menunjukkan bahwa penambahan gliserol menurunkan kuat tarik dari 4,8 MPa menjadi 2,1 MPa, tetapi menaikkan kelenturan dan mempercepat penguraian dari 14 hari menjadi 9 hari. Komposisi 2 mL gliserol dinilai paling sesuai untuk kemasan jajanan kering karena cukup kuat (3,4 MPa), tidak mudah retak ketika dilipat, dan terurai dalam 11 hari. Bioplastik kulit pisang berpotensi menggantikan kantong plastik tipis di lingkungan sekolah, dengan catatan ketahanannya terhadap air masih perlu ditingkatkan.',
            ],
            'keywords' => 'bioplastik, kulit pisang, pati, gliserol, kuat tarik, waktu urai',
            'chapters' => [
                self::chapter(1, 'PENDAHULUAN', [
                    ['Latar Belakang', [
                        ['p', 'Kantin sekolah setiap hari menghasilkan sampah kemasan dalam jumlah yang tidak sedikit. Sebagian besar jajanan, seperti gorengan, kue basah, dan keripik, dibungkus dengan kantong plastik tipis yang hanya dipakai beberapa menit lalu dibuang. Plastik jenis ini dibuat dari bahan turunan minyak bumi dan memerlukan waktu yang sangat lama untuk terurai. Akibatnya, sampah plastik menumpuk di tempat pembuangan dan sebagian terbawa ke saluran air.'],
                        ['p', 'Pengamatan awal penulis selama lima hari sekolah menunjukkan bahwa satu kantin rata-rata memakai sekitar 180 lembar kantong plastik kecil per hari. Jumlah itu belum termasuk sedotan dan gelas plastik. Ajakan membawa wadah sendiri sudah beberapa kali disampaikan, tetapi belum semua warga sekolah melakukannya. Karena itu, diperlukan bahan kemasan pengganti yang lebih mudah terurai dan tetap praktis bagi penjual.'],
                        ['p', 'Di sisi lain, penjual pisang goreng dan pisang molen di sekitar sekolah membuang kulit pisang setiap hari. Kulit pisang masih mengandung pati yang dapat diolah menjadi lembaran tipis menyerupai plastik, yang dikenal sebagai bioplastik (Hartawan & Lestyana, 2022). Lembaran dari pati saja biasanya rapuh, sehingga perlu ditambah bahan pemlastis seperti gliserol agar lebih lentur. Jumlah gliserol yang ditambahkan diduga memengaruhi kekuatan lembaran dan kecepatan terurainya.'],
                        ['p', 'Berdasarkan hal tersebut, penulis meneliti pembuatan bioplastik dari limbah kulit pisang dengan tiga komposisi gliserol, kemudian menguji kuat tarik dan waktu urainya. Hasil penelitian diharapkan menjadi dasar pembuatan kemasan jajanan yang lebih ramah lingkungan di sekolah.'],
                    ]],
                    ['Rumusan Masalah', [
                        ['ol', [
                            'Bagaimana cara membuat bioplastik dari pati limbah kulit pisang dengan alat sederhana di laboratorium sekolah?',
                            'Bagaimana pengaruh jumlah gliserol terhadap kuat tarik bioplastik kulit pisang?',
                            'Bagaimana pengaruh jumlah gliserol terhadap waktu urai bioplastik kulit pisang di dalam tanah?',
                            'Komposisi mana yang paling sesuai untuk kemasan jajanan sekolah?',
                        ]],
                    ]],
                    ['Tujuan Penelitian', [
                        ['ol', [
                            'Membuat bioplastik dari pati limbah kulit pisang dengan alat yang tersedia di laboratorium sekolah.',
                            'Mengetahui pengaruh jumlah gliserol terhadap kuat tarik bioplastik kulit pisang.',
                            'Mengetahui pengaruh jumlah gliserol terhadap waktu urai bioplastik kulit pisang di dalam tanah.',
                            'Menentukan komposisi yang paling sesuai untuk kemasan jajanan sekolah.',
                        ]],
                    ]],
                    ['Manfaat Penelitian', [
                        ['ul', [
                            'Bagi siswa: menambah pengalaman merancang percobaan, mengukur, dan mengolah data secara ilmiah.',
                            'Bagi sekolah: memberi pilihan kemasan jajanan yang lebih mudah terurai untuk mendukung program sekolah peduli lingkungan.',
                            'Bagi penjual di sekitar sekolah: memberi gambaran bahwa kulit pisang yang selama ini dibuang masih dapat dimanfaatkan.',
                        ]],
                    ]],
                ]),
                self::chapter(2, 'TINJAUAN PUSTAKA', [
                    ['Sampah Plastik Kemasan', [
                        ['p', 'Plastik kemasan sekali pakai umumnya terbuat dari polimer sintetis yang ringan, murah, dan kedap air. Sifat-sifat itulah yang membuatnya banyak dipakai, tetapi juga membuatnya sulit diuraikan oleh mikroorganisme. Kemasan tipis jarang didaur ulang karena nilainya rendah dan sering tercampur sisa makanan (Tim Penyusun, 2019). Mengurangi pemakaian dan mencari bahan pengganti menjadi dua langkah yang saling melengkapi.'],
                    ]],
                    ['Bioplastik Berbahan Pati', [
                        ['p', 'Bioplastik adalah bahan menyerupai plastik yang dibuat dari sumber hayati terbarukan, misalnya pati, selulosa, atau protein, dan dapat diuraikan oleh mikroorganisme. Pati tersusun atas amilosa dan amilopektin. Ketika dipanaskan bersama air, butir pati menyerap air, mengembang, lalu pecah sehingga terbentuk larutan kental; peristiwa ini disebut gelatinisasi. Larutan kental itu dapat dituang dan dikeringkan menjadi lembaran tipis (Hartawan & Lestyana, 2022).'],
                    ]],
                    ['Kulit Pisang sebagai Sumber Pati', [
                        ['p', 'Kulit pisang menyusun kira-kira sepertiga berat buah pisang segar dan selama ini lebih sering dibuang atau dijadikan pakan ternak. Kulit pisang yang belum terlalu matang masih menyimpan pati dalam jumlah yang cukup untuk diekstrak dengan cara sederhana, yaitu dihaluskan, disaring, lalu diendapkan (Kusumadewi, 2020). Kulit yang terpapar udara cepat berubah cokelat, sehingga perlu direndam larutan asam lemah sebelum diolah.'],
                    ]],
                    ['Gliserol sebagai Pemlastis', [
                        ['p', 'Gliserol adalah cairan kental, tidak berwarna, dan mudah menyerap air. Molekul gliserol dapat menyisip di antara rantai pati dan mengurangi ikatan antarrantai, sehingga lembaran menjadi lebih lentur dan tidak mudah patah. Namun, makin banyak pemlastis, makin kecil gaya yang diperlukan untuk menarik lembaran sampai putus (Prasetyaningrum & Wibisana, 2023).'],
                    ]],
                    ['Kuat Tarik dan Waktu Urai', [
                        ['p', 'Kuat tarik adalah gaya terbesar yang dapat ditahan bahan dibagi luas penampangnya, dinyatakan dalam megapascal (MPa). Kemasan memerlukan kuat tarik yang cukup agar tidak sobek ketika diisi. Kemampuan terurai dapat diamati dengan uji kubur, yaitu mengubur sampel di dalam tanah lalu mencatat lamanya waktu sampai sampel hancur (Wiratama, 2024).'],
                    ]],
                    ['Hipotesis', [
                        ['p', 'Makin banyak gliserol yang ditambahkan, kuat tarik bioplastik kulit pisang makin rendah dan waktu urainya di dalam tanah makin singkat.'],
                    ]],
                ]),
                self::chapter(3, 'METODE PENELITIAN', [
                    ['Waktu dan Tempat', [
                        ['p', 'Penelitian dilaksanakan pada bulan Juli sampai Agustus 2026. Pembuatan dan pengujian bioplastik dilakukan di Laboratorium Kimia '.DemoPeople::SCHOOL.', sedangkan uji urai dilakukan di kebun sekolah.'],
                    ]],
                    ['Alat dan Bahan', [
                        ['p', 'Alat yang digunakan adalah blender, saringan kain, gelas kimia 250 mL, pemanas listrik, batang pengaduk, termometer, neraca digital, cetakan kaca berukuran 20 cm × 20 cm, mikrometer sekrup, serta alat uji tarik sederhana yang dirangkai dari statif, dua penjepit, dan beban bertingkat. Bahan yang digunakan adalah kulit pisang kepok dari penjual gorengan di sekitar sekolah, akuades, gliserol, asam asetat 5%, dan larutan asam sitrat 1%.'],
                    ]],
                    ['Rancangan Penelitian', [
                        ['p', 'Variabel bebas penelitian ini adalah volume gliserol. Variabel terikatnya adalah kuat tarik dan waktu urai. Massa pati, volume akuades, volume asam asetat, suhu pemanasan, dan ukuran cetakan dibuat sama. Setiap perlakuan diulang tiga kali sehingga diperoleh sembilan lembar bioplastik. Komposisi tiap perlakuan disajikan pada Tabel 3.1.'],
                        ['table', [
                            'caption' => 'Tabel 3.1 Komposisi bahan tiap perlakuan',
                            'head' => ['Kode', 'Pati (g)', 'Akuades (mL)', 'Asam asetat 5% (mL)', 'Gliserol (mL)'],
                            'rows' => [['G1', '10', '100', '5', '1'], ['G2', '10', '100', '5', '2'], ['G3', '10', '100', '5', '3']],
                            'note' => null,
                        ]],
                    ]],
                    ['Prosedur Penelitian', [
                        ['ol', [
                            'Ekstraksi pati. Kulit pisang dicuci, dipotong kecil, dan direndam dalam larutan asam sitrat 1% selama 15 menit. Potongan kulit diblender dengan air (perbandingan 1 : 2), lalu disaring dengan kain. Filtrat didiamkan 12 jam sampai pati mengendap. Endapan dikeringkan, dihaluskan, dan diayak.',
                            'Pembuatan bioplastik. Sebanyak 10 g pati dicampur dengan 100 mL akuades, 5 mL asam asetat 5%, dan gliserol sesuai perlakuan. Campuran dipanaskan pada suhu 70-80 °C sambil diaduk selama kurang lebih 15 menit sampai mengental. Larutan dituang ke cetakan kaca, diratakan, lalu dikeringkan pada suhu ruang selama 48 jam.',
                            'Uji kuat tarik. Lembaran dipotong berukuran 2 cm × 10 cm dan tebalnya diukur di tiga titik. Sampel dijepit pada alat uji, lalu beban ditambah 50 g setiap kali sampai sampel putus. Kuat tarik dihitung dari gaya berat beban dibagi luas penampang sampel.',
                            'Uji waktu urai. Sampel berukuran 3 cm × 3 cm dikubur sedalam 5 cm di tanah kebun sekolah dan diamati setiap hari. Waktu urai dicatat ketika sampel sudah hancur dan tidak dapat diangkat utuh. Sepotong kantong plastik tipis dikubur bersamaan sebagai pembanding.',
                        ]],
                    ]],
                    ['Analisis Data', [
                        ['p', 'Data setiap perlakuan dirata-rata dari tiga ulangan, kemudian dibandingkan antarperlakuan secara deskriptif dalam bentuk tabel. Komposisi terbaik dipilih dengan mempertimbangkan kekuatan, kelenturan, dan waktu urai sekaligus.'],
                    ]],
                ]),
                self::chapter(4, 'HASIL DAN PEMBAHASAN', [
                    ['Hasil Pembuatan Bioplastik', [
                        ['p', 'Ketiga komposisi menghasilkan lembaran berwarna cokelat muda dan agak tembus pandang dengan tebal rata-rata 0,21 mm sampai 0,24 mm. Lembaran G1 terasa kaku dan retak ketika dilipat tajam. Lembaran G2 lentur dan dapat dilipat tanpa retak. Lembaran G3 paling lentur, tetapi permukaannya agak lengket ketika udara lembap.'],
                    ]],
                    ['Hasil Pengujian', [
                        ['p', 'Hasil uji kuat tarik setiap ulangan disajikan pada Tabel 4.1, sedangkan ringkasan seluruh pengujian disajikan pada Tabel 4.2.'],
                        ['table', [
                            'caption' => 'Tabel 4.1 Kuat tarik bioplastik pada tiap ulangan (MPa)',
                            'head' => ['Kode', 'Ulangan 1', 'Ulangan 2', 'Ulangan 3', 'Rata-rata'],
                            'rows' => [['G1', '4,6', '5,0', '4,8', '4,8'], ['G2', '3,3', '3,6', '3,3', '3,4'], ['G3', '2,0', '2,2', '2,1', '2,1']],
                            'note' => 'Angka pada tabel ini adalah data contoh.',
                        ]],
                        ['table', [
                            'caption' => 'Tabel 4.2 Ringkasan hasil pengujian bioplastik kulit pisang',
                            'head' => ['Kode', 'Gliserol (mL)', 'Tebal (mm)', 'Kuat tarik (MPa)', 'Pemanjangan (%)', 'Waktu urai (hari)'],
                            'rows' => [['G1', '1', '0,21', '4,8', '6', '14'], ['G2', '2', '0,22', '3,4', '13', '11'], ['G3', '3', '0,24', '2,1', '21', '9']],
                            'note' => 'Nilai adalah rata-rata tiga ulangan. Angka pada tabel ini adalah data contoh.',
                        ]],
                    ]],
                    ['Pembahasan', [
                        ['p', 'Tabel 4.2 menunjukkan bahwa kuat tarik menurun seiring bertambahnya gliserol, dari 4,8 MPa pada G1 menjadi 2,1 MPa pada G3 atau turun sekitar 56%. Sebaliknya, pemanjangan saat putus naik dari 6% menjadi 21%. Hasil ini sejalan dengan hipotesis: gliserol menyisip di antara rantai pati sehingga lembaran lebih mudah meregang, tetapi gaya yang dapat ditahannya berkurang.'],
                        ['p', 'Waktu urai juga makin singkat ketika gliserol bertambah, yaitu 14 hari pada G1, 11 hari pada G2, dan 9 hari pada G3. Gliserol mudah menyerap air, sehingga lembaran yang mengandung lebih banyak gliserol lebih cepat lembap dan lebih mudah ditumbuhi mikroorganisme tanah. Kantong plastik tipis yang dikubur sebagai pembanding tidak menunjukkan perubahan selama 30 hari pengamatan.'],
                        ['p', 'Untuk kemasan jajanan, bahan tidak cukup hanya kuat; bahan juga harus dapat dilipat tanpa retak. G1 paling kuat, tetapi mudah retak. G3 paling lentur, tetapi lemah dan lengket. G2 berada di antara keduanya, sehingga dinilai paling sesuai untuk membungkus jajanan kering seperti keripik dan kue kering. Kantong berukuran 10 cm × 15 cm dari lembaran G2 dapat menahan isi 100 g selama enam jam tanpa sobek.'],
                        ['p', 'Penelitian ini memiliki keterbatasan. Alat uji tarik yang dipakai masih sederhana, kelembapan tanah tidak dikendalikan, dan lembaran belum tahan terhadap air maupun minyak panas. Karena itu, bioplastik ini belum sesuai untuk jajanan berkuah atau gorengan yang baru diangkat.'],
                    ]],
                ]),
                self::chapter(5, 'PENUTUP', [
                    ['Simpulan', [
                        ['ol', [
                            'Bioplastik dapat dibuat dari pati limbah kulit pisang dengan alat sederhana melalui tahap ekstraksi pati, pemanasan campuran, pencetakan, dan pengeringan.',
                            'Penambahan gliserol dari 1 mL menjadi 3 mL per 10 g pati menurunkan kuat tarik dari 4,8 MPa menjadi 2,1 MPa dan menaikkan kelenturan lembaran.',
                            'Penambahan gliserol mempersingkat waktu urai di dalam tanah dari 14 hari menjadi 9 hari.',
                            'Komposisi 2 mL gliserol per 10 g pati paling sesuai untuk kemasan jajanan kering di sekolah.',
                        ]],
                    ]],
                    ['Saran', [
                        ['ol', [
                            'Penelitian lanjutan perlu menambahkan bahan penguat atau pelapis alami agar lembaran lebih tahan terhadap air.',
                            'Pengujian kuat tarik sebaiknya diulang dengan alat uji standar di laboratorium perguruan tinggi.',
                            'Keamanan bioplastik untuk kontak langsung dengan makanan perlu diuji sebelum dipakai secara luas.',
                            'Kulit pisang jenis lain dan tingkat kematangan yang berbeda dapat dibandingkan pada penelitian berikutnya.',
                        ]],
                    ]],
                ]),
            ],
            'references' => self::references(),
        ];
    }

    /**
     * General text for any other research title of the demo data.
     *
     * @return array{field: string, abstract: list<string>, keywords: string, chapters: list<array<string, mixed>>, references: list<string>}
     */
    private static function generic(string $title, ?string $abstract): array
    {
        $topic = rtrim($title, '.');
        $lead = trim((string) $abstract);

        return [
            'field' => 'Penelitian terapan',
            'abstract' => array_values(array_filter([
                $lead !== '' ? Str::limit($lead, 600, '…') : null,
                'Penelitian berjudul "'.$topic.'" ini bertujuan menjawab masalah yang penulis temui di lingkungan sekolah melalui percobaan sederhana yang dapat diulang. Penelitian dilakukan dengan tiga perlakuan dan tiga kali ulangan, kemudian datanya dibandingkan secara deskriptif. Hasil penelitian menunjukkan bahwa perlakuan B memberi hasil paling seimbang di antara ketiga perlakuan, sehingga disarankan untuk diterapkan dan diuji lebih lanjut dalam skala yang lebih besar.',
            ])),
            'keywords' => 'penelitian siswa, percobaan, perlakuan, lingkungan sekolah',
            'chapters' => [
                self::chapter(1, 'PENDAHULUAN', [
                    ['Latar Belakang', [
                        ['p', 'Lingkungan sekolah menyimpan banyak masalah sehari-hari yang dapat dijadikan bahan penelitian. Masalah yang penulis angkat dalam penelitian "'.$topic.'" berangkat dari pengamatan langsung selama kegiatan belajar dan kegiatan ekstrakurikuler. Masalah tersebut berulang setiap hari, tetapi belum ditangani dengan cara yang terukur.'],
                        ['p', 'Beberapa cara sudah pernah dicoba oleh warga sekolah, tetapi hasilnya belum dicatat dan dibandingkan. Tanpa data, sulit menentukan cara mana yang paling baik. Karena itu, penulis merancang percobaan sederhana dengan tiga perlakuan agar hasilnya dapat dibandingkan secara adil.'],
                        ['p', 'Penelitian ini diharapkan memberi gambaran awal yang dapat ditindaklanjuti oleh sekolah maupun peneliti berikutnya.'],
                    ]],
                    ['Rumusan Masalah', [
                        ['ol', [
                            'Bagaimana rancangan percobaan yang sesuai untuk masalah yang diteliti?',
                            'Bagaimana perbedaan hasil antara ketiga perlakuan?',
                            'Perlakuan mana yang paling sesuai untuk diterapkan di sekolah?',
                        ]],
                    ]],
                    ['Tujuan Penelitian', [
                        ['ol', [
                            'Merancang percobaan yang sesuai dengan alat dan bahan yang tersedia di sekolah.',
                            'Membandingkan hasil ketiga perlakuan.',
                            'Menentukan perlakuan yang paling sesuai untuk diterapkan di sekolah.',
                        ]],
                    ]],
                    ['Manfaat Penelitian', [
                        ['ul', [
                            'Bagi siswa: melatih kemampuan merancang percobaan dan mengolah data.',
                            'Bagi sekolah: memberi data awal untuk mengambil keputusan.',
                            'Bagi peneliti berikutnya: menjadi rujukan awal untuk penelitian lanjutan.',
                        ]],
                    ]],
                ]),
                self::chapter(2, 'TINJAUAN PUSTAKA', [
                    ['Kajian Teori', [
                        ['p', 'Penelitian yang baik berangkat dari teori yang sudah ada. Buku pelajaran dan panduan praktikum menjelaskan konsep dasar yang berkaitan dengan topik "'.$topic.'" (Tim Penyusun, 2019). Konsep itu penulis pakai untuk menentukan variabel yang diubah, variabel yang diukur, dan variabel yang dijaga tetap.'],
                    ]],
                    ['Penelitian Terdahulu', [
                        ['p', 'Beberapa tulisan membahas topik yang berdekatan dengan bahan dan cara yang berbeda (Kusumadewi, 2020; Wiratama, 2024). Penelitian ini berbeda karena dilakukan dengan alat yang tersedia di sekolah dan dengan kondisi lingkungan sekolah penulis.'],
                    ]],
                    ['Hipotesis', [
                        ['p', 'Terdapat perbedaan hasil antara ketiga perlakuan, dan salah satu perlakuan memberi hasil yang paling seimbang.'],
                    ]],
                ]),
                self::chapter(3, 'METODE PENELITIAN', [
                    ['Waktu dan Tempat', [
                        ['p', 'Penelitian dilaksanakan selama enam minggu di lingkungan '.DemoPeople::SCHOOL.'.'],
                    ]],
                    ['Alat dan Bahan', [
                        ['p', 'Alat dan bahan yang digunakan adalah alat ukur, wadah percobaan, lembar pengamatan, dan bahan yang mudah diperoleh di sekitar sekolah.'],
                    ]],
                    ['Prosedur Penelitian', [
                        ['ol', [
                            'Menyiapkan alat dan bahan serta menentukan tiga perlakuan (A, B, dan C).',
                            'Melaksanakan tiap perlakuan sebanyak tiga kali ulangan dengan kondisi yang dibuat sama.',
                            'Mencatat hasil setiap ulangan pada lembar pengamatan.',
                            'Merata-rata data dan membandingkannya antarperlakuan.',
                        ]],
                    ]],
                    ['Analisis Data', [
                        ['p', 'Data dianalisis secara deskriptif dengan membandingkan nilai rata-rata tiap perlakuan.'],
                    ]],
                ]),
                self::chapter(4, 'HASIL DAN PEMBAHASAN', [
                    ['Hasil Penelitian', [
                        ['p', 'Ringkasan hasil pengamatan disajikan pada Tabel 4.1.'],
                        ['table', [
                            'caption' => 'Tabel 4.1 Ringkasan hasil pengamatan',
                            'head' => ['Perlakuan', 'Ulangan 1', 'Ulangan 2', 'Ulangan 3', 'Rata-rata'],
                            'rows' => [['A', '62', '65', '63', '63,3'], ['B', '78', '81', '80', '79,7'], ['C', '71', '69', '72', '70,7']],
                            'note' => 'Nilai dalam skala 0-100. Angka pada tabel ini adalah data contoh.',
                        ]],
                    ]],
                    ['Pembahasan', [
                        ['p', 'Perlakuan B memberi nilai rata-rata tertinggi (79,7), disusul perlakuan C (70,7) dan perlakuan A (63,3). Selisih antarulangan pada tiap perlakuan kecil, sehingga data dinilai cukup konsisten. Perlakuan B dipilih karena hasilnya paling baik dan cara pelaksanaannya paling mudah diulang.'],
                        ['p', 'Penelitian ini masih terbatas pada jumlah ulangan yang sedikit dan waktu pengamatan yang pendek. Hasilnya perlu diuji lagi pada kondisi yang berbeda.'],
                    ]],
                ]),
                self::chapter(5, 'PENUTUP', [
                    ['Simpulan', [
                        ['ol', [
                            'Percobaan dapat dirancang dan dilaksanakan dengan alat serta bahan yang tersedia di sekolah.',
                            'Ketiga perlakuan memberi hasil yang berbeda.',
                            'Perlakuan B paling sesuai untuk diterapkan di sekolah.',
                        ]],
                    ]],
                    ['Saran', [
                        ['ol', [
                            'Jumlah ulangan dan lama pengamatan perlu ditambah.',
                            'Penelitian lanjutan sebaiknya melibatkan lebih banyak kelas atau lokasi.',
                        ]],
                    ]],
                ]),
            ],
            'references' => self::references(),
        ];
    }

    /**
     * @param  list<array{0: string, 1: list<array{0: string, 1: mixed}>}>  $sections
     * @return array<string, mixed>
     */
    private static function chapter(int $number, string $title, array $sections): array
    {
        $roman = ['I', 'II', 'III', 'IV', 'V'][$number - 1];
        $list = [];

        foreach ($sections as $index => [$sectionTitle, $blocks]) {
            $list[] = [
                'key' => 'sub-'.$number.'-'.($index + 1),
                'number' => $number.'.'.($index + 1),
                'title' => $sectionTitle,
                'blocks' => $blocks,
            ];
        }

        return ['key' => 'bab-'.$number, 'number' => 'BAB '.$roman, 'title' => $title, 'sections' => $list];
    }

    /**
     * Invented on purpose: every publisher and journal name carries the word "Contoh".
     *
     * @return list<string>
     */
    private static function references(): array
    {
        return [
            'Hartawan, B., & Lestyana, D. (2022). Pengantar Bioplastik Berbahan Pati. Yogyakarta: Pustaka Contoh Mandiri.',
            'Kusumadewi, T. (2020). Pemanfaatan limbah kulit buah sebagai bahan kemasan. Jurnal Contoh Sains Terapan Pelajar, 4(2), 45-52.',
            'Prasetyaningrum, S., & Wibisana, A. (2023). Pengaruh pemlastis terhadap sifat mekanik lembaran pati. Jurnal Contoh Kimia Sekolah, 7(1), 11-19.',
            'Tim Penyusun. (2019). Panduan Praktikum Kimia SMA: Polimer dan Lingkungan. Jakarta: Penerbit Contoh Edukasi.',
            'Wiratama, G. (2024). Uji sederhana penguraian bahan kemasan di dalam tanah. Buletin Contoh Lingkungan Sekolah, 2(3), 30-36.',
        ];
    }
}
