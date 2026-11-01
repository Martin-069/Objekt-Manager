@echo off
REM =====================================================
REM  ObjektManager - Auto-Push zu GitHub v2
REM  Findet Git auch wenn nicht in PATH
REM =====================================================

cd /d "C:\Users\Admin\Documents\martin069"

echo.
echo ============================================
echo   ObjektManager - Push zu GitHub
echo ============================================
echo.

REM === Git finden ===
set "GIT="
where git >nul 2>nul
if %errorlevel%==0 (
    set "GIT=git"
) else if exist "C:\Program Files\Git\cmd\git.exe" (
    set "GIT=C:\Program Files\Git\cmd\git.exe"
) else if exist "C:\Program Files (x86)\Git\cmd\git.exe" (
    set "GIT=C:\Program Files (x86)\Git\cmd\git.exe"
) else if exist "%LOCALAPPDATA%\Programs\Git\cmd\git.exe" (
    set "GIT=%LOCALAPPDATA%\Programs\Git\cmd\git.exe"
) else if exist "%USERPROFILE%\AppData\Local\Programs\Git\cmd\git.exe" (
    set "GIT=%USERPROFILE%\AppData\Local\Programs\Git\cmd\git.exe"
)

if "%GIT%"=="" (
    echo ============================================
    echo   FEHLER: Git wurde nicht gefunden.
    echo ============================================
    echo.
    echo Bitte Git installieren oder Pfad pruefen:
    echo   https://git-scm.com/download/win
    echo.
    pause
    exit /b 1
)

echo Git gefunden: %GIT%
echo.

REM === Aenderungen pruefen ===
"%GIT%" status --porcelain > "%TEMP%\_gitstatus.txt"
for %%A in ("%TEMP%\_gitstatus.txt") do set size=%%~zA
del "%TEMP%\_gitstatus.txt"

if "%size%"=="0" (
    echo Keine neuen Aenderungen gefunden.
    echo.
    timeout /t 3 >nul
    exit /b 0
)

REM === Neueste Datei fuer die Commit-Message raussuchen ===
set "LATEST="
for /f "delims=" %%F in ('dir /b /o-d "objektmanager-v*.html" 2^>nul') do (
    if not defined LATEST set "LATEST=%%F"
)
for /f "delims=" %%F in ('dir /b /o-d "begehung-tool-v*.html" 2^>nul') do (
    if not defined LATEST set "LATEST=%%F"
)
for /f "delims=" %%F in ('dir /b /o-d "index.html" 2^>nul') do (
    if not defined LATEST set "LATEST=%%F"
)

if not defined LATEST set "LATEST=update"

echo Aenderungen gefunden. Lade hoch...
echo Datei: %LATEST%
echo.

"%GIT%" add .
"%GIT%" commit -m "Auto-Push: %LATEST% (%date% %time%)"
"%GIT%" push

if errorlevel 1 (
    echo.
    echo ============================================
    echo   FEHLER beim Push - bitte pruefen!
    echo ============================================
    pause
    exit /b 1
)

echo.
echo ============================================
echo   Fertig! Alles auf GitHub.
echo ============================================
echo.
timeout /t 3 >nul
