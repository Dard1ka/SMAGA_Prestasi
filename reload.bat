@echo off
setlocal EnableExtensions
rem SMAGA Talent Hub - memuat ulang kode PHP di container (sekitar 1 detik).
rem Perlu dijalankan setelah mengubah file .php, .blade.php, config atau .env, selama repo masih ada di drive Windows.
rem Alasannya ada di docker/php/php.dev.ini dan docker/php/fpm.dev.conf. CSS dan JS tidak perlu ini.
rem Setelah mengubah .env atau config, langkah ini sekali lebih lama (membuat ulang cache config).
cd /d "%~dp0"
title SMAGA Talent Hub - reload

docker compose exec -T app sh docker/php/dev-cache.sh
if errorlevel 1 (
  echo Gagal memuat ulang. Pastikan stack berjalan: start.bat
  exit /b 1
)
echo Kode PHP sudah dimuat ulang.
