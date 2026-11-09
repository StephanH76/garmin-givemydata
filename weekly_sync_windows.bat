@echo off
REM Weekly Garmin sync for Windows Task Scheduler.
REM 1) Fetches new data from Garmin Connect into garmin.db (this folder).
REM 2) Uploads garmin.db to the private draft release "garmin-db" of this repo.
REM    The Codespace fetches it from there when you run ./start_server.sh.
REM    A draft release is only visible to you (write access), not to the public.
set REPO=StephanH76/garmin-givemydata

cd /d "%~dp0"
REM Use the portable gh (no admin install) if present, else gh from PATH
set GH=gh
if exist "%LOCALAPPDATA%\gh\bin\gh.exe" set GH="%LOCALAPPDATA%\gh\bin\gh.exe"
echo ===== %date% %time% ===== >> weekly_sync.log
venv\Scripts\python.exe garmin_givemydata.py >> weekly_sync.log 2>&1
if errorlevel 1 (
    echo Garmin sync failed, Codespace not updated. >> weekly_sync.log
    exit /b 1
)
%GH% release upload garmin-db garmin.db --clobber -R %REPO% >> weekly_sync.log 2>&1
if errorlevel 1 (
    echo No draft release yet, creating it... >> weekly_sync.log
    %GH% release create garmin-db garmin.db --draft --title "Garmin database (private draft - do not publish)" --notes "Weekly garmin.db from the laptop. Keep this as a draft so it stays private." -R %REPO% >> weekly_sync.log 2>&1
)
if errorlevel 1 (
    echo Upload of garmin.db failed. >> weekly_sync.log
    exit /b 1
)
echo garmin.db uploaded. >> weekly_sync.log
