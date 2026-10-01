# DEPLOY.md — Panduan deploy SMAGA Talent Hub (production)

> Untuk: **I** (deploy + QA). Target: `https://prestasi.sman3-smg.sch.id` di server sekolah **Ubuntu 18.04 +
> Apache 2.4.29, RAM 8 GB, dipakai bersama situs WordPress** — tanpa upgrade OS (D-18).
> Dasar keamanan: `docs/SECURITY.md` §3e (subdomain & proxy), §3j (host & Docker), §3k (supply chain), §4 (checklist).
> Versi software dicek **1 Okt 2026**. Cek ulang sebelum memasang (§3.1). Semua contoh memakai placeholder: tidak
> ada password atau kunci asli di dokumen ini.

## 0. Gambaran singkat

```
Browser ──HTTPS──> Apache 2.4.29 (host, TLS, vhost prestasi.*) ──> 127.0.0.1:8080
                                                                     │ docker-proxy
                   ┌──────────── Docker (project "sth", docker/compose.prod.yaml) ─────────────┐
                   │ web  (nginx-unprivileged, read-only)  ── network edge 172.30.10.0/24      │
                   │  └─> app (php-fpm 8.4, www-data, read-only) ── network backend (internal) │
                   │       queue, scheduler (image sama)  ── network egress (SMTP, HIBP)       │
                   │       db (MySQL 8.4, tanpa port)     ── volume sth_mysql-data             │
                   │       upload & log: volume sth_storage (storage/app/private)              │
                   └───────────────────────────────────────────────────────────────────────────┘
```

| File di repo | Fungsi |
|---|---|
| `docker/Dockerfile.prod` | image `sth-app` (php-fpm) dan `sth-web` (nginx + aset Vite) |
| `docker/compose.prod.yaml` | stack production: app, queue, scheduler, web, db + batas RAM/CPU |
| `docker/nginx/prod.conf`, `docker/php/*.prod.*`, `docker/php/{entrypoint.prod,healthcheck}.sh` | konfigurasi di dalam image |
| `docker/mysql/initdb/10-app-user.sh` | membuat user DB runtime `sth_app` (hanya DML) saat DB pertama kali dibuat |
| `docker/apache/prestasi.sman3-smg.sch.id.conf` | contoh vhost Apache host |
| `docker/env.production.example` | template `.env` production |
| `scripts/deploy/{deploy,backup,restore}.sh` | deploy, backup terenkripsi, restore |
| `.github/workflows/ci.yml`, `.github/dependabot.yml` | CI + update dependensi |

Keputusan teknis yang perlu diketahui:
- **Port proxy = `127.0.0.1:8080`** (ARCHITECTURE §9). SECURITY.md menulis 8081; bila 8080 sudah dipakai di
  host, set `STH_HTTP_PORT=8081` untuk semua perintah compose/skrip **dan** ubah `ProxyPass` di vhost.
- **Subnet `edge` = `172.30.10.0/24`, gateway `172.30.10.1`.** Gateway ini satu-satunya IP yang dipercaya
  TrustProxies (`STH_TRUSTED_PROXY` di `.env`). Bila bentrok dengan jaringan sekolah, ganti `STH_EDGE_SUBNET`,
  `STH_EDGE_GATEWAY` dan `STH_TRUSTED_PROXY` bersamaan.
- Tiga user DB: `root` (hanya backup/restore), `sth_migrate` (hanya migrasi saat deploy), `sth_app` (runtime,
  hanya `SELECT/INSERT/UPDATE/DELETE`). Akibatnya kode aplikasi tidak bisa `TRUNCATE`/DDL saat runtime.
- **Jangan pernah** menulis `STH_OTP_TEST_MODE` (atau `STH_OTP_DEV_BYPASS`) di `.env` production, bahkan `=false`
  (CLAUDE.md aturan 7). Nilai bawaannya sudah `false`; `deploy.sh` menolak jalan bila baris itu ada, dan compose
  memaksa `APP_ENV=production` + `APP_DEBUG=false`.

## 1. Sebelum menyentuh server (koordinasi)

- [ ] Akses SSH + `sudo` ke server; siapa lagi yang punya akses root (SECURITY §5 no. 3).
- [ ] Pengelola situs WordPress setuju dengan perubahan global Apache (modul, `ServerTokens`) dan firewall.
- [ ] Pengelola DNS zona `sman3-smg.sch.id` siap menambah record A `prestasi` (§5).
- [ ] Keputusan Ubuntu Pro (SECURITY §3j, U-01). Tanpa Pro, risiko ditandatangani Kepala Sekolah.
- [ ] Lokasi backup di luar server + **siapa memegang kunci privat backup** (§11).
- [ ] Jendela pemeliharaan: di luar jam sekolah (build image memakai CPU/RAM beberapa menit).

## 2. Persiapan server (Ubuntu 18.04, tanpa upgrade OS)

### 2.1 Cek kondisi awal (catat hasilnya)
```bash
lsb_release -a; uname -r; free -m; df -h / /var/lib; swapon --show
apache2 -v; dpkg -l apache2 openssl | tail -n 2; apache2ctl -M | sort
sudo ss -ltnp                                    # 127.0.0.1:8080 harus KOSONG
ip -4 route; ip -4 addr                          # 172.30.10.0/24 tidak boleh dipakai
command -v docker && docker version              # ada docker apt lama?
timedatectl                                      # "System clock synchronized: yes"
```
Butuh RAM bebas ± 4,5 GB untuk STH (lihat `docker/compose.prod.yaml`). Bila `free -m` menunjukkan kurang,
turunkan dulu `--innodb-buffer-pool-size` dan `mem_limit` sebelum deploy.

### 2.2 Ubuntu Pro (bila token tersedia)
```bash
sudo pro attach <TOKEN>
pro status                                        # esm-infra dan esm-apps: enabled
sudo apt-get update && sudo apt-get install --only-upgrade apache2 openssl libssl1.1 linux-generic
sudo reboot
dpkg -l apache2 | tail -n1                        # versi berakhiran +esmN
```

### 2.3 Paket dasar, waktu, update otomatis, SSH
```bash
sudo apt-get update
sudo apt-get install -y git curl ca-certificates ufw fail2ban unattended-upgrades xz-utils procps iptables
sudo timedatectl set-ntp true                    # TTL OTP & log bergantung jam yang benar
sudo dpkg-reconfigure -plow unattended-upgrades
```
`/etc/ssh/sshd_config` (pastikan login dengan key sudah jalan **sebelum** reload):
```
PasswordAuthentication no
PermitRootLogin no
AllowUsers <admin1> <admin2>
```
`sudo systemctl reload sshd`, lalu aktifkan jail `sshd` di fail2ban (`/etc/fail2ban/jail.local`: `[sshd] enabled = true`).

### 2.4 User layanan `sth` dan direktori
```bash
sudo adduser --system --group --home /opt/sth --shell /usr/sbin/nologin sth
sudo chmod 750 /opt/sth
sudo -u sth mkdir -p /opt/sth/backups /opt/sth/logs
```
`sth` tidak bisa login SSH; admin menjalankan perintah dengan `sudo -u sth -H bash ...`.
`www-data` dan user situs utama **tidak** boleh masuk grup `sth` maupun `docker`.

## 3. Docker Engine static binaries (menggantikan docker-ce 24.0.2 dari apt)

### 3.1 Versi (dicek 1 Okt 2026)
| Komponen | Versi | Sumber |
|---|---|---|
| Docker Engine (static) | **29.8.1** (15 Sep 2026), membawa containerd 2.3.5; runc 1.5.1 (sejak 29.8.0) | docs.docker.com/engine/release-notes/29/ |
| Docker Compose plugin | **v5.5.1** (3 Sep 2026) | github.com/docker/compose/releases |
| Buildx plugin | **v0.37.1** (11 Sep 2026) | github.com/docker/buildx/releases |

Sebelum memasang, buka ketiga halaman itu dan pakai versi terbaru. Catatan resmi Docker: static binaries tidak
mendapat update otomatis, jadi rutinitas bulanan §13 **WAJIB**. cgroup v1 (bawaan 18.04) deprecated, didukung
minimal sampai Mei 2029: itu tenggat migrasi OS.

### 3.2 Uji di staging dulu, lalu lepas docker apt (bila ada)
Lakukan seluruh §3 di VM/clone dengan kernel yang sama terlebih dahulu (SECURITY §3j). Di server:
```bash
sudo systemctl stop docker docker.socket containerd 2>/dev/null || true
sudo apt-get purge -y docker-ce docker-ce-cli containerd.io docker-compose-plugin docker-buildx-plugin || true
sudo rm -f /etc/apt/sources.list.d/docker*.list     # cegah versi apt terpasang lagi
```
`/var/lib/docker` tidak dihapus. Bila `containerd` baru gagal start karena config lama, pindahkan
`/etc/containerd/config.toml` ke `config.toml.old`.

### 3.3 Pasang binary
```bash
V=29.8.1
cd /tmp
curl -fsSLO "https://download.docker.com/linux/static/stable/x86_64/docker-$V.tgz"
sudo mkdir -p /opt/docker && sha256sum "docker-$V.tgz" | sudo tee -a /opt/docker/CHANGELOG   # catat sendiri
sudo mkdir -p "/opt/docker/$V" && sudo tar -xzf "docker-$V.tgz" -C "/opt/docker/$V" --strip-components=1
sudo ln -sfn "/opt/docker/$V" /opt/docker/current
for b in /opt/docker/current/*; do sudo ln -sfn "$b" "/usr/local/bin/$(basename "$b")"; done
```

### 3.4 Unit systemd (diadaptasi dari moby/contrib/init/systemd dan containerd.service)
```bash
sudo tee /etc/systemd/system/containerd.service >/dev/null <<'EOF'
[Unit]
Description=containerd container runtime
Documentation=https://containerd.io
After=network.target local-fs.target
[Service]
ExecStartPre=-/sbin/modprobe overlay
ExecStart=/usr/local/bin/containerd
Type=notify
Delegate=yes
KillMode=process
Restart=always
RestartSec=5
LimitNPROC=infinity
LimitCORE=infinity
TasksMax=infinity
OOMScoreAdjust=-999
[Install]
WantedBy=multi-user.target
EOF

sudo tee /etc/systemd/system/docker.socket >/dev/null <<'EOF'
[Unit]
Description=Docker Socket for the API
[Socket]
ListenStream=/run/docker.sock
SocketMode=0660
SocketUser=root
SocketGroup=docker
[Install]
WantedBy=sockets.target
EOF

sudo tee /etc/systemd/system/docker.service >/dev/null <<'EOF'
[Unit]
Description=Docker Application Container Engine
Documentation=https://docs.docker.com
After=network-online.target docker.socket containerd.service
Wants=network-online.target containerd.service
Requires=docker.socket
[Service]
Type=notify
ExecStart=/usr/local/bin/dockerd -H fd:// --containerd=/run/containerd/containerd.sock
ExecReload=/bin/kill -s HUP $MAINPID
TimeoutStartSec=0
Restart=always
RestartSec=2
StartLimitBurst=3
StartLimitInterval=60s
LimitNPROC=infinity
LimitCORE=infinity
TasksMax=infinity
Delegate=yes
KillMode=process
OOMScoreAdjust=-500
[Install]
WantedBy=multi-user.target
EOF
```

### 3.5 `/etc/docker/daemon.json`
```bash
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json >/dev/null <<'EOF'
{ "no-new-privileges": true, "live-restore": true,
  "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "5" } }
EOF
sudo groupadd -f docker
sudo usermod -aG docker sth          # juga admin tepercaya; JANGAN www-data
sudo systemctl daemon-reload && sudo systemctl enable --now containerd docker.socket docker
```

### 3.6 Plugin Compose dan Buildx (verifikasi checksum)
```bash
sudo mkdir -p /usr/local/lib/docker/cli-plugins && cd /tmp
C=v5.5.1
curl -fsSLO "https://github.com/docker/compose/releases/download/$C/docker-compose-linux-x86_64"
curl -fsSLO "https://github.com/docker/compose/releases/download/$C/docker-compose-linux-x86_64.sha256"
sha256sum -c docker-compose-linux-x86_64.sha256
sudo install -m 755 docker-compose-linux-x86_64 /usr/local/lib/docker/cli-plugins/docker-compose
B=v0.37.1
curl -fsSLO "https://github.com/docker/buildx/releases/download/$B/buildx-$B.linux-amd64"
sha256sum "buildx-$B.linux-amd64"        # bandingkan dengan checksum di halaman rilis buildx
sudo install -m 755 "buildx-$B.linux-amd64" /usr/local/lib/docker/cli-plugins/docker-buildx
echo "$(date -I) engine $V compose $C buildx $B" | sudo tee -a /opt/docker/CHANGELOG
```

### 3.7 Verifikasi
```bash
docker version --format '{{.Server.Version}}'; runc --version; containerd --version
docker compose version; docker buildx version
docker info | grep -Ei 'cgroup|storage driver|swap'     # cgroup v1 + overlay2; "No swap limit support" = wajar
docker run --rm php:8.4-fpm-bookworm php -v && docker run --rm mysql:8.4 mysqld --version
```

## 4. Firewall (ufw + chain DOCKER-USER)

Docker mem-bypass ufw untuk port yang dipublish. Lapis pertama: semua port STH hanya bind `127.0.0.1`
(sudah di compose). Lapis kedua: chain `DOCKER-USER`. Ganti `<IF>` dengan interface publik (`ip -4 route show
default` → `dev eth0`/`ens18`), dan `<ADMIN_CIDR>` dengan IP/jaringan admin.
```bash
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow from <ADMIN_CIDR> to any port 22 proto tcp     # DULU, sebelum enable
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
sudo tee -a /etc/ufw/after.rules >/dev/null <<'EOF'

# STH: block new inbound connections from the public interface to containers (SECURITY §3j)
*filter
:DOCKER-USER - [0:0]
-A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
-A DOCKER-USER -i <IF> -j DROP
COMMIT
EOF
sudo ufw enable && sudo ufw reload && sudo systemctl restart docker
sudo iptables -L DOCKER-USER -n -v
```
Dari komputer lain: `nmap -Pn -p 1-65535 <ip-server>` hanya menampilkan 22/80/443 (setelah deploy, 8080 dan
3306 harus tertutup).

## 5. DNS
Record `A  prestasi  →  <IP publik server>` di zona `sman3-smg.sch.id`. SEBAIKNYA juga
`CAA 0 issue "letsencrypt.org"` (berlaku juga untuk situs utama, koordinasikan). Cek: `dig +short prestasi.sman3-smg.sch.id`.

## 6. Apache vhost + TLS (Let's Encrypt)

### 6.1 Modul dan pengaturan global
```bash
sudo a2enmod proxy proxy_http headers ssl rewrite
```
`rewrite` hampir pasti sudah dipakai WordPress (permalink); vhost STH sendiri **tidak** memakai mod_rewrite, PHP,
CGI atau `.htaccess`. Jangan aktifkan `proxy_ajp`, `proxy_ftp`, `http2` untuk STH. Setelah disetujui pengelola
WordPress, di `/etc/apache2/conf-available/security.conf`: `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`.

### 6.2 Tahap 1: vhost HTTP saja + sertifikat
```bash
sudo mkdir -p /var/www/letsencrypt/.well-known/acme-challenge
cd /opt/sth/app      # checkout repo (§7.1); atau salin file vhost dari repo secara manual
sudo sed -n '/^# >>> http/,/^# <<< http/p' docker/apache/prestasi.sman3-smg.sch.id.conf \
  | sudo tee /etc/apache2/sites-available/prestasi.sman3-smg.sch.id.conf >/dev/null
sudo a2ensite prestasi.sman3-smg.sch.id && sudo apache2ctl configtest && sudo systemctl reload apache2

sudo apt-get remove -y certbot python3-certbot-apache || true   # paket bionic sudah tua
sudo snap install --classic certbot && sudo ln -sf /snap/bin/certbot /usr/bin/certbot
sudo certbot certonly --webroot -w /var/www/letsencrypt -d prestasi.sman3-smg.sch.id \
  -m <email-admin@sman3-smg.sch.id> --agree-tos --no-eff-email \
  --deploy-hook "systemctl reload apache2"
sudo certbot renew --dry-run
```
Bila snap bermasalah di host ini: image `certbot/certbot` dengan `certonly --webroot` yang sama + cron + `apachectl graceful`.

### 6.3 Tahap 2: vhost lengkap (HTTPS + proxy)
```bash
sudo cp docker/apache/prestasi.sman3-smg.sch.id.conf /etc/apache2/sites-available/prestasi.sman3-smg.sch.id.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
curl -sI https://prestasi.sman3-smg.sch.id | grep -i -E 'strict-transport|server'   # 503 wajar sebelum §8
```
`+TLSv1.3` butuh apache2 ≥ `2.4.29-1ubuntu4.12`; bila `configtest` menolak, perbarui paket apache2 (bukan OS).
Isi vhost (sudah diuji `httpd -t` + curl, lihat `docs/dev-notes/W1-E.md`): redirect 80→443 kecuali ACME,
`ProxyPreserveHost On`, header `X-Forwarded-For/-Host/-Prefix`, `Forwarded`, `X-Real-IP`, `Proxy` dari klien
dibuang, `X-Forwarded-Proto https`, HSTS tanpa `includeSubDomains`, `Protocols http/1.1`, batas body 25 MB.

### 6.4 Pantau kedaluwarsa sertifikat
Let's Encrypt tidak lagi mengirim email kedaluwarsa. Pasang pemantau dari luar (uptime monitor dengan cek SSL)
dengan alert bila sisa < 14 hari (SECURITY §3e/§3l).

## 7. Kode, secrets, `.env`

### 7.1 Deploy key dan clone
```bash
sudo -u sth -H mkdir -p -m 700 /opt/sth/.ssh
sudo -u sth -H ssh-keygen -t ed25519 -N '' -C sth-deploy@prestasi -f /opt/sth/.ssh/id_ed25519
sudo cat /opt/sth/.ssh/id_ed25519.pub     # daftarkan sebagai Deploy key READ-ONLY di repo GitHub
sudo -u sth -H bash -c 'ssh-keyscan github.com >> ~/.ssh/known_hosts'
sudo -u sth -H git clone git@github.com:<org>/<repo>.git /opt/sth/app
sudo -u sth -H git -C /opt/sth/app config core.fileMode false
sudo chmod 750 /opt/sth/app
```

### 7.2 Secrets MySQL
```bash
cd /opt/sth/app
sudo -u sth mkdir -m 700 secrets
for s in db_root_password db_migrate_password db_app_password; do
  openssl rand -hex 32 | sudo -u sth tee "secrets/$s.txt" >/dev/null
done
sudo chmod 644 secrets/*.txt      # dibaca user mysql (uid 999) di kontainer; folder 700 menutup akses user host lain
```
Hex = tanpa tanda kutip atau `$`. Simpan salinan password root DB di tempat aman (brankas/password manager sekolah).

### 7.3 `.env`
```bash
sudo -u sth cp docker/env.production.example .env && sudo chmod 600 .env
echo "base64:$(openssl rand -base64 32)"        # → APP_KEY
sudo cat secrets/db_app_password.txt            # → DB_PASSWORD (harus sama persis)
sudo -u sth -H nano /opt/sth/app/.env           # isi semua CHANGE_ME
```
| Kunci | Nilai production | Alasan |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | juga dipaksa compose; boot guard menolak `APP_DEBUG=true` |
| `APP_KEY` | dibuat di server | bocor = dekripsi cookie/kolom + RCE (SECURITY §3j) |
| `APP_URL` | `https://prestasi.sman3-smg.sch.id` | dasar link & TrustHosts |
| `DB_USERNAME` / `DB_PASSWORD` | `sth_app` / isi `secrets/db_app_password.txt` | user runtime DML-only |
| `SESSION_COOKIE` / `SESSION_SECURE_COOKIE` | `__Host-sth_session` / `true` | kebal cookie tossing dari domain induk |
| `SESSION_ENCRYPT` | `true` | |
| `STH_REQUIRE_STAFF_2FA` | `true` | TOTP wajib untuk staf |
| `STH_TRUSTED_PROXY` | `172.30.10.1` | gateway network `edge` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` (30 hari) / `info` | |
| `MAIL_*` | placeholder sampai §9 | `MAIL_MAILER=log/array` ditolak di production |
| `STH_OTP_TEST_MODE` | **tidak ditulis** | default `false`; baris apa pun = deploy ditolak |

### 7.4 Ringkasan izin
| Path | Pemilik | Mode |
|---|---|---|
| `/opt/sth`, `/opt/sth/app` | sth:sth | 750 |
| `/opt/sth/app/.env`, `/opt/sth/backup.env` | sth:sth | 600 |
| `/opt/sth/app/secrets/` | sth:sth | 700 (file di dalamnya 644) |
| `/opt/sth/backups/` | sth:sth | 700 (dibuat oleh backup.sh) |

Cek isolasi: `sudo -u www-data cat /opt/sth/app/.env` → *Permission denied*; `id www-data` tanpa grup `docker`/`sth`.

## 8. Deploy pertama
```bash
sudo -u sth -H bash /opt/sth/app/scripts/deploy/deploy.sh --first
```
Urutan kerja skrip: cek `.env`/secrets → `git fetch` + checkout `origin/main` → build `sth-app:<commit>` dan
`sth-web:<commit>` → start `db` (pertama kali: user `sth_migrate` + `sth_app` dibuat) → `php artisan migrate --force`
sebagai `sth_migrate` → start app/queue/scheduler/web (entrypoint menjalankan `config:cache`, `route:cache`,
`event:cache`, `view:cache`) → smoke test `/up` → catat ke `/opt/sth/deploy-history.log`.

Deploy berikutnya cukup `sudo -u sth -H bash /opt/sth/app/scripts/deploy/deploy.sh` (tambahan otomatis: backup
`pre-deploy-<commit>` dan mode maintenance selama migrasi). Tag/commit tertentu: `--ref v1.2.0`.

Bila inisialisasi DB pertama gagal (mis. password berisi karakter aneh), volume setengah jadi harus dihapus
**hanya pada instalasi pertama, saat belum ada data**: `docker compose -f docker/compose.prod.yaml down -v`, perbaiki,
ulangi `--first`.

Verifikasi (SECURITY §3e/§4):
```bash
cd /opt/sth/app; alias sthc='docker compose -f /opt/sth/app/docker/compose.prod.yaml'
sthc ps                                               # semua Up, app/web/db (healthy)
sthc exec app id                                      # uid=33(www-data)
sthc exec app php artisan about --only=environment,cache   # production, debug OFF, semua CACHED
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.test' http://127.0.0.1:8080/        # bukan 200
curl -s https://prestasi.sman3-smg.sch.id/.env -o /dev/null -w '%{http_code}\n'             # 404
curl -sI https://prestasi.sman3-smg.sch.id/ | grep -i -E 'set-cookie|strict-transport|content-security'
curl -s -H 'X-Forwarded-Host: evil.test' https://prestasi.sman3-smg.sch.id/lupa-password | grep -c evil.test   # 0
```
Cookie harus `__Host-sth_session; secure; httponly; samesite=lax; path=/` tanpa `domain`. `request()->ip()`
harus IP klien asli (cek event login di log keamanan).

### 8.1 Data acuan + akun Admin pertama
Production tidak punya akun apa pun setelah migrasi. Jalankan sekali setelah `--first` (keduanya aman diulang):
```bash
sthc exec app php artisan db:seed --force              # peran & izin, 36 kelas, talenta, bobot default, pengaturan
                                                       # (DemoSeeder dan tanda tangan contoh TIDAK jalan di production)
sthc exec app php artisan sth:create-admin             # interaktif: nama, nama pengguna, email, kata sandi 2x
```
- Kata sandi Admin: min. 12 karakter, huruf besar + kecil, angka, simbol, dan tidak ada di daftar kebocoran
  (HIBP, butuh jaringan `egress`). Perintah menolak kata sandi lemah. Jangan pernah menaruh kata sandi di argumen
  perintah (tersimpan di riwayat shell); untuk skrip pakai `--password-stdin`.
- Sudah ada Admin → perintah menolak kecuali `--additional`. Maksimal 3 akun Admin (2 bernama + 1 break-glass,
  SECURITY §3m-9). Akun break-glass: buat dengan `--additional`, simpan kata sandi + kode pemulihan 2FA di amplop tersegel.
- Akun dibuat aktif dengan email dianggap terverifikasi (operator server yang membuatnya). Saat login pertama Admin
  wajib memasang verifikasi dua langkah (TOTP) di `/akun/2fa`. Pembuatan akun tercatat di audit (`account.admin_created`).
- Setelah itu Admin membuat akun staf lain dari **Pengaturan › Pengguna & peran** (termasuk "Tetapkan Kepala
  Sekolah"), mengunggah tanda tangan + stempel di **Surat & tanda tangan**, lalu mengimpor siswa.
- Scheduler menjalankan `sth:arsip-otomatis` setiap hari 01.00 WIB dan menerapkan penggantian Kepala Sekolah yang
  sudah lewat masa tunda 24 jam (tiap 5 menit). Cek: `sthc exec scheduler php artisan schedule:list`.

## 9. Email noreply (menyusul, D-15)
Sampai relay/SMTP sekolah tersedia, `MAIL_*` tetap placeholder dan job email gagal di `failed_jobs`.
**Go-live menunggu langkah ini** (SECURITY §4: OTP nyata diuji end-to-end). Saat siap:
1. Pilih relay (mis. Google Workspace SMTP relay `smtp-relay.gmail.com:587` bila domain memakai Workspace, atau
   penyedia email transaksional). Buat akun/kredensial khusus `noreply@sman3-smg.sch.id`.
2. DNS: SPF, DKIM, DMARC untuk domain pengirim (koordinasi pengelola DNS).
3. Isi `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` di `.env`, lalu
   `sthc up -d --force-recreate app queue scheduler`.
4. Uji kirim email OTP ke akun uji; `sthc exec app php artisan queue:failed` harus kosong.

## 10. Operasional
```bash
sthc ps; sthc logs -f --tail=100 app queue scheduler web
sthc exec app sh -c 'ls storage/logs && tail -n 50 storage/logs/laravel-$(date +%F).log'
sthc exec app php artisan queue:failed
docker stats --no-stream                 # pakai RAM vs batas di compose
df -h /var/lib/docker                    # alert bila ≥ 80 %
```
Log vhost Apache: `/var/log/apache2/prestasi-{access,error}.log`.

## 11. Backup terenkripsi (harian)

**Kunci:** backup dienkripsi dengan kunci **publik** `age`; kunci privat **tidak pernah** ada di server.
1. Pasang `age` di server (tidak ada di apt bionic): unduh `age-v1.3.2-linux-amd64.tar.gz` dari
   github.com/FiloSottile/age/releases, cocokkan SHA-256 dengan halaman rilis, lalu
   `sudo install -m 755 age/age age/age-keygen /usr/local/bin/`.
2. Di laptop tepercaya (bukan server): `age-keygen -o sth-backup-key.txt`. Baris `# public key: age1…` disalin
   ke server: `/opt/sth/backup-recipients.txt` (boleh 2 kunci, satu per baris, untuk 2 pemegang kunci).
   File kunci privat: cetak + simpan di 2 USB di brankas Kepsek/TU; catat siapa pemegangnya.
3. `/opt/sth/backup.env` (sth, 600), contoh:
   ```bash
   STH_BACKUP_RETENTION_DAYS=14
   STH_BACKUP_RCLONE_REMOTE=sekolah-nas:sth-backup      # opsional, off-site (rclone config sebagai user sth)
   STH_BACKUP_REMOTE_RETENTION_DAYS=90
   STH_BACKUP_PING_URL=https://<monitor>/ping/<uuid>    # opsional, alert bila backup tidak jalan
   ```
   Alternatif `gpg`: `STH_BACKUP_ENCRYPTION=gpg` + `STH_BACKUP_GPG_RECIPIENT=<fingerprint>` (kunci publik diimpor
   ke keyring user sth).
4. Cron user sth (`sudo crontab -u sth -e`):
   ```
   30 1 * * * bash /opt/sth/app/scripts/deploy/backup.sh >> /opt/sth/logs/backup.log 2>&1
   ```
Hasil: `/opt/sth/backups/sth-<tanggal-jam>/{db.sql.gz.age, private.tar.gz.age, SHA256SUMS, meta.txt}`.
Dump dan arsip langsung dienkripsi saat streaming (plaintext tidak pernah tertulis ke disk). Password root DB
dibaca di dalam kontainer, tidak muncul di daftar proses host.

## 12. Restore

**Uji restore bulanan (WAJIB, SECURITY §3j):** bawa kunci privat dari brankas, lalu:
```bash
sudo -u sth -H bash /opt/sth/app/scripts/deploy/restore.sh \
  --from /opt/sth/backups/sth-<tanggal-jam> --identity /media/<usb>/sth-backup-key.txt --target test
```
Mode `test` mengimpor ke project terpisah `sth-restoretest` (production tidak tersentuh), menampilkan jumlah
tabel/baris dan memeriksa arsip file, lalu menghapus project uji itu. Catat di log restore: tanggal, nama backup,
jumlah `users`/`migrations`, siapa yang menguji. Cabut USB setelah selesai (kunci tidak disalin ke server).

**Restore production** (setelah insiden/kerusakan data, koordinasi dengan lead):
```bash
sudo -u sth -H bash /opt/sth/app/scripts/deploy/restore.sh \
  --from /opt/sth/backups/sth-<tanggal-jam> --identity /media/<usb>/sth-backup-key.txt --target production
```
Skrip meminta ketik `RESTORE`, membuat backup pengaman `pre-restore-*`, menyalakan maintenance, menghentikan
queue/scheduler, `DROP` + impor database, memindahkan `storage/app/private` lama ke
`private.before-restore-<stamp>` (hapus manual setelah dicek), lalu menyalakan layanan lagi.
`--db-only` / `--files-only` untuk restore sebagian.

## 13. Rutinitas bulanan (minggu ke-2, di luar jam sekolah)
- [ ] `pro status` + `sudo apt-get update && sudo apt-get upgrade` (paket host; bukan upgrade rilis OS). Reboot bila kernel baru.
- [ ] Docker: bandingkan `docker version` dengan rilis terbaru (§3.1); update ≤ 48 jam bila ada CVE HIGH/CRITICAL
      runc/containerd. Langkah: uji staging → backup → `sthc stop` → `sudo systemctl stop docker docker.socket containerd`
      → pasang `/opt/docker/<ver>` + ganti symlink `current` + symlink `/usr/local/bin` → start → `sthc up -d` →
      smoke test → catat di `/opt/docker/CHANGELOG`. Simpan 2 versi terakhir untuk rollback.
- [ ] Merge PR Dependabot (composer/npm/digest image/actions) yang CI-nya hijau, lalu `deploy.sh`.
- [ ] `certbot renew --dry-run`; sisa masa sertifikat > 14 hari.
- [ ] Uji restore (§12) + cek `/opt/sth/logs/backup.log` dan salinan off-site.
- [ ] `df -h`, `docker system df`; bersihkan build cache lama: `docker builder prune --filter until=720h -f`.
- [ ] Tinjau `/var/log/auth.log`, `fail2ban-client status sshd`, `last`, dan `sthc exec app php artisan queue:failed`.

## 14. Rollback
| Situasi | Langkah |
|---|---|
| Versi aplikasi baru bermasalah, tanpa perubahan skema DB | `sudo -u sth -H bash /opt/sth/app/scripts/deploy/deploy.sh --rollback <tag lama>` (tag dari `/opt/sth/deploy-history.log`; 3 tag terakhir disimpan) |
| Migrasi sudah mengubah skema/data | restore backup `pre-deploy-<tag>` (§12, `--target production`), lalu `--rollback <tag lama>` |
| Update Docker Engine bermasalah | arahkan `/opt/docker/current` + symlink `/usr/local/bin` ke versi lama, restart `containerd docker`, `sthc up -d` |
| Vhost Apache bermasalah | `sudo a2dissite prestasi.sman3-smg.sch.id && sudo systemctl reload apache2` (situs WordPress tidak terpengaruh) |

Migrasi production hanya maju (CLAUDE.md aturan 8): jangan menjalankan `migrate:rollback`/`migrate:fresh` di server.

## 15. Isolasi dari situs WordPress (SECURITY §3e)
- [ ] File STH milik `sth`, mode 750/600; `www-data` tidak bisa membaca `/opt/sth`.
- [ ] Grup `docker` hanya admin tepercaya + `sth`; bukan `www-data`/user situs utama.
- [ ] MySQL STH hanya di kontainer (tanpa port); tidak ada user/password DB yang sama dengan WordPress.
- [ ] Port STH hanya `127.0.0.1:8080`; `nmap` dari luar tidak melihatnya.
- [ ] Kontainer STH tidak me-mount path situs utama, dan sebaliknya.
- [ ] Vhost STH tanpa DocumentRoot berisi file (`/var/www/letsencrypt` hanya token ACME), tanpa `.htaccess`.
- [ ] Log vhost STH terpisah. SEBAIKNYA PHP situs utama via php-fpm dengan user sendiri (bukan `mod_php`).
- [ ] Bila situs utama terkompromi: anggap host terkompromi (SECURITY §3l) — rotasi `APP_KEY`, password DB, secret.

## 16. Troubleshooting
| Gejala | Periksa |
|---|---|
| Apache 503 / "Service Unavailable" | `sthc ps` (web up?), `curl -H 'Host: prestasi.sman3-smg.sch.id' http://127.0.0.1:8080/up` |
| `app` restart terus | `sthc logs app`: entrypoint `config:cache` gagal = `.env` salah atau boot guard (APP_DEBUG, MAIL_MAILER, cookie `__Host-`, flag OTP) |
| Peringatan `view:cache failed` di log app | ada view Blade rusak; halaman itu error saat dibuka, aplikasi lain tetap jalan — laporkan ke tim |
| `deploy.sh`: "foreign Host header was accepted" | TrustHosts belum aktif di `bootstrap/app.php` (lihat dev-note W1-E) |
| Login rate limit mengunci satu sekolah | TrustProxies belum aktif / `STH_TRUSTED_PROXY` ≠ gateway `edge` (`docker network inspect sth_edge`) |
| `Pool overlaps with other one on this address space` | subnet `edge` bentrok; ganti `STH_EDGE_SUBNET`/`STH_EDGE_GATEWAY`/`STH_TRUSTED_PROXY` |
| Disk penuh | `docker system df`, `/opt/sth/backups`, log di volume `sth_storage` |

## 17. Checklist go-live (infrastruktur, dari SECURITY §4)
- [ ] Ubuntu Pro ter-attach **atau** risiko tanpa Pro ditandatangani Kepsek.
- [ ] Docker ≥ 29.8.1, runc ≥ 1.5.1, containerd ≥ 2.3.5; apt docker-ce dihapus; rutinitas update diuji sekali di staging.
- [ ] Apache: modul sesuai kesepakatan, `ServerTokens Prod`, `ProxyRequests Off`, tanpa h2 di vhost STH, `certbot renew --dry-run` OK, pemantau sertifikat aktif.
- [ ] Isolasi host (§15) dan firewall (§4) terverifikasi dengan `nmap`.
- [ ] `sthc exec app id` = www-data; tanpa docker.sock/privileged; `APP_DEBUG=false`; `about` = semua CACHED.
- [ ] `/.env`, `/.git/HEAD`, `/storage/logs/laravel.log`, `/composer.lock` → 404.
- [ ] Cookie `__Host-`, HSTS tanpa `includeSubDomains`, Host asing ditolak, `X-Forwarded-Host` palsu tidak mengubah link.
- [ ] SMTP noreply (§9) berjalan; `STH_OTP_TEST_MODE` tidak ada di `.env`.
- [ ] Backup harian terenkripsi + salinan off-site + **uji restore berhasil** (tanggal & bukti dicatat).
- [ ] Kontak insiden (SECURITY §3l) terisi.
