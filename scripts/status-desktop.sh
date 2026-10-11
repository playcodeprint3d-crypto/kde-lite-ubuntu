#!/usr/bin/env bash

DISPLAY_NUM=":1"
VNC_PORT="5901"
WEB_PORT="6080"

echo "=== Estado de KDE Plasma Desktop ==="

# Verificar VNC (busca proceso vncserver/Xtigervnc o socket en puerto 5901)
if pgrep -f "Xvnc :1" > /dev/null 2>&1 \
   || pgrep -f "Xtigervnc :1" > /dev/null 2>&1 \
   || pgrep -f "vncserver :1" > /dev/null 2>&1 \
   || ss -tlpn 2>/dev/null | grep -q ":${VNC_PORT}"; then
    echo "  [ACTIVO] Servidor VNC en ${DISPLAY_NUM} (puerto ${VNC_PORT})"
else
    echo "  [INACTIVO] Servidor VNC no está corriendo."
fi

# Verificar noVNC
for P in 8080 6080; do
    if pgrep -f "websockify.*${P}" > /dev/null 2>&1; then
        echo "  [ACTIVO] noVNC / Websockify escuchando en el puerto ${P}"
    else
        echo "  [INACTIVO] noVNC no está corriendo en el puerto ${P}."
    fi
done

# Verificar KWin / Plasma
if pgrep -f "plasmashell" > /dev/null 2>&1; then
    echo "  [ACTIVO] Shell de KDE Plasma (plasmashell) ejecutándose."
else
    echo "  [INFO] plasmashell no detectado en memoria."
fi

# Verificar Gestor de Ventanas (KWin)
if pgrep -f "kwin_x11" > /dev/null 2>&1; then
    echo "  [ACTIVO] Gestor de ventanas (kwin_x11) activo (barras de título y bordes)."
else
    echo "  [ALERTA] Gestor de ventanas (kwin_x11) inactivo."
fi

# Verificar Antigravity 2.0 Web Hub (puerto 3000)
if pgrep -f "agy.*--hub-port=3000" > /dev/null 2>&1 \
   || ss -tlpn 2>/dev/null | grep -q ":3000 "; then
    echo "  [ACTIVO] Antigravity 2.0 Web Hub escuchando en el puerto 3000"
    if [ -n "${CODESPACE_NAME:-}" ]; then
        echo "  [URL]    https://${CODESPACE_NAME}-3000.app.github.dev/"
    fi
else
    echo "  [INACTIVO] Antigravity 2.0 Web Hub no está corriendo en el puerto 3000."
fi

# Verificar visibilidad de puertos en GitHub Codespaces
if [ -n "${CODESPACE_NAME:-}" ] && command -v gh >/dev/null 2>&1; then
    echo "--- Visibilidad en GitHub Codespaces ---"
    PORTS_JSON=$(gh codespace ports -c "$CODESPACE_NAME" --json sourcePort,visibility 2>/dev/null || echo "[]")
    for P in 8080 6080 3000; do
        VIS=$(echo "$PORTS_JSON" | jq -r ".[] | select(.sourcePort == $P) | .visibility" 2>/dev/null || echo "no detectado")
        if [ "$VIS" = "public" ]; then
            echo "  [PÚBLICO] Puerto $P está público (accesible externamente)."
        else
            echo "  [ALERTA]  Puerto $P está como '$VIS' (se requiere visibilidad pública)."
        fi
    done
fi

echo "====================================="

