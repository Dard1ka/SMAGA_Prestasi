@echo off
setlocal EnableExtensions
rem SMAGA Talent Hub - menyalakan stack pengembangan lewat Docker.
rem Klik dua kali, atau jalankan "start.bat" dari folder repo. Butuh Docker Desktop yang sudah berjalan.
rem Variabel opsional: STH_NO_BROWSER=1 (tidak membuka browser), STH_NO_PAUSE=1 (tanpa jeda di akhir).
cd /d "%~dp0"
title SMAGA Talent Hub - start

echo [1/6] Mengecek Docker...
docker info >nul 2>&1
if errorlevel 1 (
  echo Docker belum berjalan. Buka Docker Desktop, tunggu "Engine running", lalu jalankan start.bat lagi.
  goto :fail
)

set "FIRST_RUN="
if not exist ".env" (
  echo [2/6] Membuat .env dari .env.example...
  copy /y ".env.example" ".env" >nul || goto :fail
  set "FIRST_RUN=1"
) else (
  echo [2/6] .env sudah ada.
)

echo [3/6] Menyiapkan image PHP dan dependensi...
docker compose build app || goto :fail
if not exist "vendor\autoload.php" (
  docker compose run --rm --no-deps app composer install --no-interaction || goto :fail
)
if defined FIRST_RUN (
  docker compose run --rm --no-deps app php artisan key:generate --force || goto :fail
)

echo [4/6] Menyalakan MySQL, app, web, queue, scheduler, dan Mailpit...
docker compose up -d --wait mysql || goto :fail
docker compose up -d app web queue scheduler mailpit || goto :fail

echo [5/6] Migrasi database...
docker compose exec -T app php artisan migrate --force || goto :fail
if defined FIRST_RUN (
  echo       Mengisi data awal dan data demo fiktif...
  docker compose exec -T app php artisan db:seed --force || goto :fail
)

echo [6/6] Menyiapkan aset frontend...
if not exist "public\build\manifest.json" (
  docker compose run --rm node sh -c "npm install && npm run build" || goto :fail
)

rem Cache khusus php-fpm di dalam container supaya pindah halaman cepat (docker/php/fpm.dev.conf).
rem Kalau langkah ini gagal aplikasi tetap jalan, hanya lebih lambat.
docker compose exec -T app sh docker/php/dev-cache.sh --force

echo.
echo Siap!
echo   Aplikasi  : http://127.0.0.1:8080
echo   Email dev : http://127.0.0.1:8025  (Mailpit)
echo   Matikan   : stop.bat
if not defined STH_NO_BROWSER start "" "http://127.0.0.1:8080"
if not defined STH_NO_PAUSE pause
exit /b 0

:fail
echo.
echo Gagal menyalakan. Baca pesan di atas, lalu coba lagi.
if not defined STH_NO_PAUSE pause
exit /b 1
