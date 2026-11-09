@echo off
REM Weekly Garmin sync for Windows Task Scheduler.
REM 1) Fetches new data from Garmin Connect into garmin.db (this folder).
REM 2) Copies garmin.db into the Codespace so the Claude Garmin connector sees it.
REM Set CODESPACE_NAME below (shown by: gh codespace list). Leave empty to skip step 2.
set CODESPACE_NAME=didactic-couscous-5g74wjx94v46h747q

cd /d "%~dp0"
echo ===== %date% %time% ===== >> weekly_sync.log
venv\Scripts\python.exe garmin_givemydata.py >> weekly_sync.log 2>&1
if errorlevel 1 (
    echo Garmin sync failed, Codespace not updated. >> weekly_sync.log
    exit /b 1
)
if "%CODESPACE_NAME%"=="" exit /b 0
gh codespace cp -c %CODESPACE_NAME% garmin.db remote:/workspaces/garmin-givemydata/garmin.db >> weekly_sync.log 2>&1
if errorlevel 1 (
    echo Copy to Codespace failed. >> weekly_sync.log
    exit /b 1
)
echo Codespace updated. >> weekly_sync.log
