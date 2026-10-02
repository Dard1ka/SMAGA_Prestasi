@echo off
setlocal EnableExtensions
rem SMAGA Talent Hub - memuat ulang kode PHP di container (sekitar 1 detik).
rem Perlu dijalankan setelah mengubah file .php atau .blade.php, selama repo masih ada di drive Windows.
rem Alasannya ada di docker/php/php.dev.ini. CSS dan JS tidak perlu ini.
cd /d "%~dp0"
title SMAGA Talent Hub - reload

docker compose exec -T app sh -c "kill -USR2 1"
if errorlevel 1 (
  echo Gagal memuat ulang. Pastikan stack berjalan: start.bat
  exit /b 1
)
echo Kode PHP sudah dimuat ulang.
