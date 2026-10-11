#!/usr/bin/env bash
set -euo pipefail

DISPLAY_NUM=":1"
WEB_PORT="6080"

echo "=========================================================="
echo " Deteniendo KDE Plasma Lite Desktop"
echo "=========================================================="

echo "[+] Deteniendo websockify / noVNC..."
pkill -f "websockify" 2>/dev/null || true

echo "[+] Deteniendo servidor VNC en ${DISPLAY_NUM}..."
vncserver -kill "${DISPLAY_NUM}" 2>/dev/null || true
pkill -f "Xvnc ${DISPLAY_NUM}" 2>/dev/null || true
pkill -f "Xtigervnc ${DISPLAY_NUM}" 2>/dev/null || true

echo "[+] Limpiando procesos de KDE Plasma residuales..."
pkill -u "$USER" -f "plasmashell" 2>/dev/null || true
pkill -u "$USER" -f "kwin_x11" 2>/dev/null || true
pkill -u "$USER" -f "kactivitymanagerd" 2>/dev/null || true
pkill -u "$USER" -f "kded5" 2>/dev/null || true

# Limpieza de bloqueos
rm -f "/tmp/.X1-lock" "/tmp/.X11-unix/X1" 2>/dev/null || sudo rm -f "/tmp/.X1-lock" "/tmp/.X11-unix/X1" 2>/dev/null || true

echo "[+] Escritorio detenido con éxito."
echo "=========================================================="

