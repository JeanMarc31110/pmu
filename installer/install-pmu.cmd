@echo off
setlocal
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-pmu.ps1" %*
endlocal
