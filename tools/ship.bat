@echo off
rem Double-click: runs all tests and, only if they all pass, pushes to GitHub.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0ship.ps1" %*
echo.
pause
