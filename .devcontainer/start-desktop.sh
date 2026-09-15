#!/usr/bin/env bash
set -euo pipefail

# 0. Bloqueo seguro anti-concurrencia sin fuga de descriptores
START_LOCK="/tmp/.start-desktop.pid"
if [ -f "$START_LOCK" ]; then
    PID=$(cat "$START_LOCK" 2>/dev/null || echo "")
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        if grep -q "start-desktop" "/proc/$PID/cmdline" 2>/dev/null; then
            echo "[!] start-desktop.sh ya se encuentra en ejecución en PID $PID. Omitiendo."
            exit 0
        fi
    fi
fi
echo "$$" > "$START_LOCK"
trap 'rm -f "$START_LOCK"' EXIT INT TERM

DISPLAY_NUM=":1"
DISP_INDEX="1"
VNC_PORT="5901"
WEB_PORT="6080"
LOG_DIR="$HOME/.vnc"
mkdir -p "$LOG_DIR"

echo "=========================================================="
echo " Iniciando KDE Plasma Lite Desktop"
echo "=========================================================="

# 1. Limpieza de archivo de lock de instalación si es huérfano
LOCK_FILE="/tmp/.install-desktop.lock"
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        echo "[!] Instalación en curso (PID: $PID). Esperando finalización..."
        while kill -0 "$PID" 2>/dev/null; do
            sleep 3
        done
        echo "[+] Instalación concluida."
    else
        rm -f "$LOCK_FILE" 2>/dev/null || true
    fi
fi

# 2. Memoria compartida para Chrome y Electron
sudo mount -o remount,size=2G /dev/shm 2>/dev/null || true

# 3. Iniciar servicio D-Bus del sistema si no está corriendo
if ! sudo service dbus status >/dev/null 2>&1; then
    echo "[+] Iniciando servicio D-Bus del sistema..."
    sudo service dbus start >/dev/null 2>&1 || true
fi

# 4. Desactivar bloqueo de pantalla de KDE
if command -v kwriteconfig5 >/dev/null 2>&1; then
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Autolock false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key LockOnResume false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Timeout 0 2>/dev/null || true
fi

# 5. Asegurar permisos de directorio socket X11 y runtime dir
export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
sudo mkdir -p /tmp/.X11-unix
sudo chown root:root /tmp/.X11-unix 2>/dev/null || true
sudo chmod 1777 /tmp/.X11-unix 2>/dev/null || true

# 6. Comprobar si VNC está activo; si no, limpiar residuos y arrancar
if ss -tlpn 2>/dev/null | grep -E "(:${VNC_PORT}\s)" >/dev/null 2>&1 || pgrep -x Xtigervnc >/dev/null 2>&1 || pgrep -x Xvnc >/dev/null 2>&1; then
    echo "[!] El servidor VNC ya está activo en la pantalla ${DISPLAY_NUM} (puerto ${VNC_PORT})."
else
    echo "[+] Limpiando bloqueos, sockets y PIDs antiguos de X11..."
    rm -f "/tmp/.X${DISP_INDEX}-lock" "/tmp/.X11-unix/X${DISP_INDEX}" "${LOG_DIR}"/*"${DISPLAY_NUM}.pid" 2>/dev/null || true
    sudo rm -f "/tmp/.X${DISP_INDEX}-lock" "/tmp/.X11-unix/X${DISP_INDEX}" 2>/dev/null || true

    echo "[+] Iniciando servidor TigerVNC en ${DISPLAY_NUM}..."
    setsid nohup vncserver "${DISPLAY_NUM}" \
        -geometry 1366x768 \
        -depth 24 \
        -localhost yes \
        -SecurityTypes None \
        -cleanstale \
        -noreset \
        </dev/null >> "${LOG_DIR}/vncserver.log" 2>&1 || {
            echo "[-] Falló el inicio de vncserver. Ver log en: ${LOG_DIR}/vncserver.log"
            exit 1
        }
    echo "[+] Servidor VNC iniciado correctamente."
fi

# Esperar a que el puerto VNC responda
for i in {1..10}; do
    if ss -tlpn 2>/dev/null | grep -E "(:${VNC_PORT}\s)" >/dev/null 2>&1; then
        break
    fi
    sleep 0.5
done

# 7. Sincronización de portapapeles bidireccional
export DISPLAY="${DISPLAY_NUM}"
if command -v vncconfig >/dev/null 2>&1 && ! pgrep -x vncconfig >/dev/null 2>&1; then
    nohup vncconfig -nowin </dev/null >/dev/null 2>&1 &
fi
if command -v autocutsel >/dev/null 2>&1; then
    pgrep -x autocutsel >/dev/null 2>&1 || autocutsel -fork
    pgrep -x autocutsel >/dev/null 2>&1 || autocutsel -selection CLIPBOARD -fork
fi

# 8. Iniciar websockify / noVNC en puertos 8080 y 6080
for PORT in 8080 6080; do
    if ss -tlpn 2>/dev/null | grep -E "(:${PORT}\s)" >/dev/null 2>&1; then
        echo "[!] websockify ya está corriendo en el puerto ${PORT}."
    else
        echo "[+] Iniciando puente web noVNC en el puerto ${PORT}..."
        websockify -D --web /usr/share/novnc "${PORT}" "localhost:${VNC_PORT}"
    fi
done

sleep 1

# 9. Iniciar Antigravity 2.0 Web Interactive Hub en puerto 3000 (vía ttyd)
if [ ! -x /usr/local/bin/ttyd ]; then
    echo "[+] Descargando servidor web ttyd para Antigravity..."
    sudo curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.x86_64" 2>/dev/null || true
    sudo chmod +x /usr/local/bin/ttyd 2>/dev/null || true
fi

sudo tee /usr/local/bin/agy-web-session >/dev/null << 'EOF'
#!/usr/bin/env bash
WS_DIR="$(find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1)"
[ -z "$WS_DIR" ] && WS_DIR="/workspaces/kde-lite-ubuntu"
cd "$WS_DIR" 2>/dev/null || cd "$HOME"
export TERM=xterm-256color
export LANG=C.UTF-8
export LC_ALL=C.UTF-8

clear

# TrueColor ANSI Gradients (Play Code Brand Orange-Gold, Antigravity Pixel Art Style)
C1='\033[38;2;255;85;0m'
C2='\033[38;2;255;115;0m'
C3='\033[38;2;255;145;0m'
C4='\033[38;2;255;175;0m'
C5='\033[38;2;255;200;0m'
C6='\033[38;2;255;220;25m'
C7='\033[38;2;255;235;60m'

R='\033[0m'
WHITE='\033[1;37m'
ORANGE='\033[1;38;2;255;165;0m'
CYAN='\033[1;38;2;80;190;255m'
GREEN='\033[1;38;2;50;215;75m'
MUTED='\033[38;2;140;160;180m'
DIVIDER='\033[38;2;60;80;110m'

echo ""
echo -e "  ${C1}   ▄██▄        ▄█   ▄██▄      ${R}${ORANGE}▶ PLAY CODE${R} ${WHITE}• Plataforma Educativa${R}"
echo -e "  ${C2}  ▄██▀        ▄█▀    ▀██▄     ${R}  ${WHITE}Google Antigravity 2.0${R} ${CYAN}(Web AI Hub)${R}"
echo -e "  ${C3} ▄██▀        ▄█▀      ▀██▄    ${R}  ${MUTED}¡Bienvenido/a a tu entorno interactivo de IA!${R}"
echo -e "  ${C4}███▄        ▄█▀        ▄███   ${R}  ${GREEN}⚡ Modo: TURBO${R} ${MUTED}(Permisos auto-aprobados)${R}"
echo -e "  ${C5} ▀██▄      ▄█▀        ▄██▀    ${R}  ${MUTED}Directorio:${R} ${CYAN}$WS_DIR${R}"
echo -e "  ${C6}  ▀██▄    ▄█▀        ▄██▀     ${R}  ${DIVIDER}──────────────────────────────────────────${R}"
echo -e "  ${C7}   ▀██▄   █▀        ▄██▀      ${R}  ${CYAN}💡 Tip:${R} ${MUTED}Escribe tus instrucciones para comenzar${R}"
echo ""

AGY_BIN="/usr/local/bin/agy"
[ ! -f "$AGY_BIN" ] && [ -f "/home/codespace/.gemini/bin/agy" ] && AGY_BIN="/home/codespace/.gemini/bin/agy"
[ ! -f "$AGY_BIN" ] && AGY_BIN="$(command -v agy || echo "")"

if [ -n "$AGY_BIN" ] && [ -x "$AGY_BIN" ]; then
    while true; do
        "$AGY_BIN" --add-dir="$WS_DIR" --dangerously-skip-permissions "$@"
        echo ""
        echo -e "\033[1;33m[!] Sesión de Antigravity finalizada. Presiona ENTER para reiniciar...\033[0m"
        read -r
        clear
    done
else
    echo -e "\033[1;31m[-] Error: No se encontró el binario agy. Iniciando shell interactivo...\033[0m"
    exec bash
fi
EOF
sudo chmod +x /usr/local/bin/agy-web-session 2>/dev/null || true

if [ -x /usr/local/bin/ttyd ]; then
    if ! ss -tlpn 2>/dev/null | grep -E "(:3000\s)" >/dev/null 2>&1; then
        echo "[+] Iniciando Antigravity 2.0 Web Hub en el puerto 3000..."
        setsid nohup /usr/local/bin/ttyd \
            --port 3000 \
            --writable \
            -t disableLeaveAlert=true \
            -t titleFixed='Google Antigravity 2.0 Web Hub' \
            -t fontSize=15 \
            -t fontFamily='JetBrains Mono, Menlo, Consolas, monospace' \
            -t 'theme={"background": "#141618", "foreground": "#f0f6fc", "cursor": "#58a6ff"}' \
            /usr/local/bin/agy-web-session </dev/null >>"${LOG_DIR}/antigravity-hub.log" 2>&1 &

        # Esperar hasta que el puerto 3000 esté activo
        for check in {1..10}; do
            if ss -tlpn 2>/dev/null | grep -E "(:3000\s)" >/dev/null 2>&1; then
                echo "[+] Antigravity 2.0 Web Hub activo y verificado en puerto 3000."
                break
            fi
            sleep 1
        done
    else
        echo "[+] Antigravity Web Hub ya está activo en puerto 3000."
    fi
fi

# 9b. Iniciar Antigravity IDE 2.x en Display :2 y websockify en puerto 4000
DISP_IDE=":2"
VNC_PORT_IDE="5902"
WEB_PORT_IDE="4000"

if ! command -v openbox >/dev/null 2>&1; then
    sudo apt-get update -y >/dev/null 2>&1 || true
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends openbox >/dev/null 2>&1 || true
fi

mkdir -p "$HOME/.config/openbox"
cat << 'OBCFG' > "$HOME/.config/openbox/rc.xml"
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc">
  <applications>
    <application class="*">
      <decor>no</decor>
      <maximized>yes</maximized>
      <fullscreen>yes</fullscreen>
    </application>
  </applications>
</openbox_config>
OBCFG

mkdir -p "$HOME/.vnc"
cat << 'IDECFG' > "$HOME/.vnc/xstartup-ide"
#!/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export DISPLAY=":2"
export XDG_CURRENT_DESKTOP=Antigravity
export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true

if command -v autocutsel >/dev/null 2>&1; then
    autocutsel -fork
    autocutsel -selection CLIPBOARD -fork
fi

if command -v openbox >/dev/null 2>&1; then
    openbox &
fi

WS_DIR="$(find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1)"
[ -z "$WS_DIR" ] && WS_DIR="/workspaces/kde-lite-ubuntu"

while true; do
    if [ -x /usr/local/bin/antigravity-ide ]; then
        /usr/local/bin/antigravity-ide --no-sandbox --disable-gpu --disable-dev-shm-usage "$WS_DIR"
    fi
    sleep 2
done
IDECFG
chmod +x "$HOME/.vnc/xstartup-ide"

if ! ss -tlpn 2>/dev/null | grep -E "(:${VNC_PORT_IDE}\s)" >/dev/null 2>&1; then
    echo "[+] Iniciando TigerVNC en ${DISP_IDE} para Antigravity IDE (puerto ${VNC_PORT_IDE})..."
    rm -f "/tmp/.X2-lock" "/tmp/.X11-unix/X2" 2>/dev/null || true
    sudo rm -f "/tmp/.X2-lock" "/tmp/.X11-unix/X2" 2>/dev/null || true
    setsid nohup vncserver "${DISP_IDE}" \
        -geometry 1366x768 \
        -depth 24 \
        -localhost yes \
        -SecurityTypes None \
        -cleanstale \
        -noreset \
        -xstartup "$HOME/.vnc/xstartup-ide" \
        </dev/null >> "${LOG_DIR}/vncserver-ide.log" 2>&1 || true
fi

if ! ss -tlpn 2>/dev/null | grep -E "(:${WEB_PORT_IDE}\s)" >/dev/null 2>&1; then
    echo "[+] Iniciando websockify en puerto ${WEB_PORT_IDE} para Antigravity IDE..."
    websockify -D --web /usr/share/novnc "${WEB_PORT_IDE}" "localhost:${VNC_PORT_IDE}"
fi

# 10. Asegurar visibilidad pública de los puertos y supervisión continua en segundo plano
if [ -n "${CODESPACE_NAME:-}" ]; then
    echo "[+] Iniciando supervisor de puertos y servicios (8080, 6080, 3000, 4000)..."
    ENSURE_BIN="/usr/local/bin/ensure-ports-public.sh"
    WS_DIR="$(find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1)"
    [ -z "$WS_DIR" ] && WS_DIR="/workspaces/kde-lite-ubuntu"
    if [ ! -f "$ENSURE_BIN" ] && [ -f "$WS_DIR/scripts/ensure-ports-public.sh" ]; then
        ENSURE_BIN="$WS_DIR/scripts/ensure-ports-public.sh"
    fi
    if [ -f "$ENSURE_BIN" ]; then
        setsid nohup bash "$ENSURE_BIN" --daemon > "${LOG_DIR}/ensure-ports-public.log" 2>&1 &
    fi
fi

echo "=========================================================="
echo " ¡Escritorio KDE Plasma Lite y Antigravity Web listos!"
echo "=========================================================="
echo " Acceso Web:"
echo " 1. Escritorio KDE (noVNC):      https://${CODESPACE_NAME:-codespace}-8080.app.github.dev/vnc.html"
echo " 2. Escritorio KDE Alt:          https://${CODESPACE_NAME:-codespace}-6080.app.github.dev/vnc.html"
echo " 3. Antigravity CLI (ttyd):      https://${CODESPACE_NAME:-codespace}-3000.app.github.dev/"
echo " 4. Antigravity IDE 2.0 Web:     https://${CODESPACE_NAME:-codespace}-4000.app.github.dev/vnc.html"
echo "=========================================================="
