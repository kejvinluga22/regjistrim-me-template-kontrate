@echo off
title Kontratat e Studenteve - CIT
cd /d "%~dp0"
set PORT=8766
set URL=http://127.0.0.1:%PORT%/

rem Nese programi eshte hapur tashme, vetem hap shfletuesin
netstat -an | findstr /c:"127.0.0.1:%PORT% " | findstr /c:"LISTENING" >nul
if %errorlevel%==0 (
    start "" "%URL%"
    exit /b
)

rem Hap shfletuesin pas 2 sekondash (kur serveri eshte gati)
start "" /b powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process '%URL%'"

echo.
echo   ======================================================
echo     KONTRATAT E STUDENTEVE - CIT
echo.
echo     Programi po hapet ne shfletues:  %URL%
echo.
echo     MOS E MBYLLNI KETE DRITARE gjate punes.
echo     Kur te mbaroni, mbylleni kete dritare (X).
echo   ======================================================
echo.
"php\php.exe" -c "php\php.ini" -d extension_dir="%~dp0php\ext" -S 127.0.0.1:%PORT% -t "app" "app\router.php" >nul 2>&1

echo   Programi u mbyll. Nese nuk u hap fare, provoni perseri START.bat
pause >nul
