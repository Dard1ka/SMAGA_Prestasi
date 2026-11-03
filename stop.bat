@echo off
setlocal EnableExtensions
rem SMAGA Talent Hub - mematikan stack pengembangan. Data database TETAP tersimpan (volume tidak dihapus).
rem Variabel opsional: STH_NO_PAUSE=1 (tanpa jeda di akhir).
cd /d "%~dp0"
title SMAGA Talent Hub - stop

docker info >nul 2>&1
if errorlevel 1 (
  echo Docker tidak berjalan, tidak ada yang perlu dimatikan.
  goto :end
)

echo Mematikan semua container SMAGA Talent Hub...
docker compose --profile frontend down
if errorlevel 1 (
  echo Gagal mematikan. Baca pesan di atas.
  goto :end
)
echo Selesai. Data database tetap tersimpan. Nyalakan lagi dengan start.bat.

:end
if not defined STH_NO_PAUSE pause
