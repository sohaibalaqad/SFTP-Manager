@echo off
rem SFTP File Manager launcher for Windows — double-click to start.
rem First run downloads the FrankenPHP engine once (PHP included), verifies its SHA-256,
rem creates the local settings, then starts the app on this computer only and opens the browser.
rem Note: Octane's keep-alive mode is not available on Windows, so each click opens its own SFTP connection.
setlocal
chcp 65001 >nul
title SFTP File Manager
cd /d "%~dp0"

set "VERSION=v1.12.7"
set "SUM=6c110edc2b658e52b241f54bda5a1e5fa050f068a5363d1e2aec04214d5fe57f"

rem --- 1) Engine (downloaded once) ------------------------------------------
if not exist "engine\" (
    echo First run: downloading the engine ^(~57 MB^), only once...
    powershell -NoProfile -ExecutionPolicy Bypass -Command ^
      "$ErrorActionPreference='Stop'; $ProgressPreference='SilentlyContinue';" ^
      "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;" ^
      "Invoke-WebRequest 'https://github.com/php/frankenphp/releases/download/%VERSION%/frankenphp-windows-x86_64.zip' -OutFile 'engine.zip';" ^
      "if ((Get-FileHash 'engine.zip' -Algorithm SHA256).Hash.ToLower() -ne '%SUM%') { Remove-Item 'engine.zip'; throw 'Checksum mismatch - file deleted' };" ^
      "Expand-Archive 'engine.zip' -DestinationPath 'engine' -Force; Remove-Item 'engine.zip'"
    if errorlevel 1 (
        echo.
        echo Download failed. Check your internet connection and try again.
        if exist "engine\" rmdir /s /q engine
        pause
        exit /b 1
    )
)

set "FP="
for /r "engine" %%f in (frankenphp.exe) do if exist "%%f" set "FP=%%f"
if not defined FP (
    echo Engine not found. Delete the "engine" folder and run again.
    pause
    exit /b 1
)
for %%d in ("%FP%") do set "ENGINE=%%~dpd"

rem The Windows build ships PHP extensions as DLLs: enable the ones the app needs (php.ini next to the engine).
if not exist "%ENGINE%php.ini" (
    > "%ENGINE%php.ini" (
        echo extension_dir="%ENGINE%ext"
        echo extension=openssl
        echo extension=mbstring
        echo extension=fileinfo
        echo extension=sodium
        echo extension=gmp
        echo extension=curl
        echo extension=zip
        echo extension=bz2
        echo upload_max_filesize=64M
        echo post_max_size=70M
        echo memory_limit=512M
    )
)
set "PHPRC=%ENGINE%"

rem --- 2) Local settings with a unique secret key for this computer ----------
if not exist ".env" (
    copy /y ".env.example" ".env" >nul
    "%FP%" php-cli artisan key:generate --force --no-interaction >nul
)

rem --- 3) Already running? Otherwise pick a free port starting at 8010 ---------
for /f %%p in ('powershell -NoProfile -Command "for($p=8010;$p -lt 8020;$p++){ try { $r=Invoke-WebRequest -UseBasicParsing ('http://127.0.0.1:'+$p+'/') -TimeoutSec 2; if($r.Content -match 'SFTP'){ 'RUNNING:'+$p; exit } } catch {} }; $p=8010; while($true){ try { $c=New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1',$p); $c.Close(); $p++ } catch { break } }; $p"') do set "RESULT=%%p"

if "%RESULT:~0,8%"=="RUNNING:" (
    echo Already running: http://127.0.0.1:%RESULT:~8%
    start "" "http://127.0.0.1:%RESULT:~8%"
    exit /b 0
)
set "PORT=%RESULT%"

echo.
echo  SFTP File Manager is running at: http://127.0.0.1:%PORT%
echo  To stop it: close this window.
echo.

rem Open the browser as soon as the app answers.
start "" /b powershell -NoProfile -WindowStyle Hidden -Command "for($i=0;$i -lt 120;$i++){ try { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:%PORT%/' -TimeoutSec 2 | Out-Null; Start-Process 'http://127.0.0.1:%PORT%'; break } catch { Start-Sleep -Milliseconds 500 } }"

rem Only reachable from this computer (127.0.0.1).
"%FP%" php-server --root public --listen 127.0.0.1:%PORT%
pause
