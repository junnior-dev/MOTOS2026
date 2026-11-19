@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Repuestos de motos - MOTOS2026

set PGBIN=C:\Program Files\PostgreSQL\18\bin
set PGDATA=C:\Program Files\PostgreSQL\18\data

echo.
echo  == Repuestos de motos ==
echo.

"%PGBIN%\pg_isready.exe" -h 127.0.0.1 -p 5432 >nul 2>&1
if errorlevel 1 (
    echo  Iniciando PostgreSQL...
    start "" /min "%PGBIN%\pg_ctl.exe" -D "%PGDATA%" -l "%TEMP%\pg_motos.log" start
    timeout /t 8 /nobreak >nul
) else (
    echo  PostgreSQL ya esta en marcha.
)

echo  Abriendo http://127.0.0.1:8000
start "" http://127.0.0.1:8000
php artisan serve --host=127.0.0.1 --port=8000
