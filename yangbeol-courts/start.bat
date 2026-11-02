@echo off
cd /d "%~dp0"
where node >nul 2>nul
if errorlevel 1 (
  echo Node.js 24 or newer is required. Install it from https://nodejs.org/
  pause
  exit /b 1
)
echo Open http://localhost:8787 in your browser after the server starts.
node --use-system-ca server.mjs
pause
