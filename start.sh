#!/usr/bin/env bash
set -e

# Render avvia automaticamente questo comando
# Assicurati che la variabile d'ambiente GEMINI_API_KEY sia impostata

# Default al port 8000 se non fornito da Render
export PORT=${PORT:-8000}

# Avvia Uvicorn
python3 -m uvicorn deco:app --host 0.0.0.0 --port $PORT