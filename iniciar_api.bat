@echo off
rem Sobe a API do MachSense. Deixe esta janela aberta durante a apresentacao.
rem Depois, no site: menu Simulacao > Iniciar.
cd /d "%~dp0"
title MachSense API
python -m uvicorn api:app --host 127.0.0.1 --port 8000
echo.
echo A API foi encerrada. Se apareceu um erro acima, copie o texto e confira o README.
pause
