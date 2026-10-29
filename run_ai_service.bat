@echo off
title IntelliFile Local AI Microservice
echo ========================================================
echo       IntelliFile Local AI Engine & Microservice        
echo ========================================================
echo.

set PYTHON_EXE=%LOCALAPPDATA%\Programs\Python\Python311\python.exe

if not exist "%PYTHON_EXE%" (
    set PYTHON_EXE=python
)

echo Starting Local AI Microservice on http://127.0.0.1:5000 ...
echo Press Ctrl+C to stop.
echo.

"%PYTHON_EXE%" "%~dp0ai_service\app.py"
pause

