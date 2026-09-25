#!/usr/bin/env bash
# =============================================================================
# start-desktop.sh â€” PlayCode KDE Lite + Antigravity 2.0
# Sin set -e: cada servicio es independiente, un fallo no mata al resto
# =============================================================================

# Anti-concurrencia simple
START_LOCK="/tmp/.start-desktop.pid"
if [ -f "$START_LOCK" ]; then
    OLD_PID=$(cat "$START_LOCK" 2>/dev/null || echo "")
    if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
        echo "[!] start-desktop.sh ya corre en PID $OLD_PID. Saliendo."
        exit 0
    fi
fi
echo "$$" > "$START_LOCK"
trap 'rm -f "$START_LOCK"' EXIT INT TERM

echo "=========================================================="
echo " PlayCode â€” Iniciando escritorio KDE + Antigravity 2.0"
echo "=========================================================="

LOG_DIR="$HOME/.vnc"
mkdir -p "$LOG_DIR"

export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true

# 1. Memoria compartida
sudo mount -o remount,size=2G /dev/shm 2>/dev/null || true

# 2. D-Bus
if ! sudo service dbus status >/dev/null 2>&1; then
    sudo service dbus start >/dev/null 2>&1 || true
fi

# 3. KDE desactivar bloqueo
if command -v kwriteconfig5 >/dev/null 2>&1; then
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Autolock false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key LockOnResume false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Timeout 0 2>/dev/null || true
    kwriteconfig5 --file kwinrc --group Compositing --key Enabled false 2>/dev/null || true
fi

# 4. Wallpaper
WP_FILE="/usr/share/wallpapers/playcode-wallpaper.jpg"
WS_DIR="$(find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1)"
[ -z "$WS_DIR" ] && WS_DIR="/workspaces/kde-lite-ubuntu"
if [ ! -f "$WP_FILE" ] && [ -f "$WS_DIR/assets/wallpaper.jpg" ]; then
    sudo cp -f "$WS_DIR/assets/wallpaper.jpg" "$WP_FILE" 2>/dev/null || true
fi
if [ -f "$WP_FILE" ] && command -v kwriteconfig5 >/dev/null 2>&1; then
    for c in 1 2 3 4; do
        kwriteconfig5 --file plasma-org.kde.plasma.desktop-appletsrc \
            --group Containments --group "$c" \
            --group Wallpaper --group org.kde.image \
            --group General --key Image "file://$WP_FILE" 2>/dev/null || true
    done
fi

# 4b. Limpieza de accesos directos obsoletos de Antigravity
rm -f "$HOME/Desktop/antigravity"*.desktop 2>/dev/null || true
sudo rm -f /usr/share/applications/antigravity*.desktop 2>/dev/null || true
rm -f "$HOME/.local/share/applications/antigravity"*.desktop 2>/dev/null || true
PLASMA_CFG="$HOME/.config/plasma-org.kde.plasma.desktop-appletsrc"
if [ -f "$PLASMA_CFG" ]; then
    sed -i -E 's|applications:antigravity[^,]*\.desktop,?||g' "$PLASMA_CFG"
    sed -i -E 's|file:///usr/share/applications/antigravity[^,]*\.desktop,?||g' "$PLASMA_CFG"
    sed -i -E 's|file:///home/codespace/Desktop/antigravity[^,]*\.desktop,?||g' "$PLASMA_CFG"
    sed -i 's/launchers=,/launchers=/g; s/,,/,/g; s/,$//g' "$PLASMA_CFG"
fi

# 5. X11 socket
sudo mkdir -p /tmp/.X11-unix 2>/dev/null || true
sudo chmod 1777 /tmp/.X11-unix 2>/dev/null || true

# 5b. Matar servicios previos (crítico al reiniciar el Codespace)
echo "[*] Limpiando procesos previos de VNC y websockify..."
# Matar VNC servers de forma limpia primero
vncserver -kill :1 2>/dev/null || true
vncserver -kill :2 2>/dev/null || true
vncserver -kill :3 2>/dev/null || true
sleep 1
# Matar procesos residuales por si vncserver -kill no alcanzó
pkill -f "Xtigervnc.*:1" 2>/dev/null || true
pkill -f "Xtigervnc.*:2" 2>/dev/null || true
pkill -f "Xtigervnc.*:3" 2>/dev/null || true
# Matar websockify en los puertos que usaremos
pkill -f "websockify.*8080" 2>/dev/null || true
pkill -f "websockify.*6080" 2>/dev/null || true
pkill -f "websockify.*4000" 2>/dev/null || true
pkill -f "websockify.*5000" 2>/dev/null || true
sleep 1

# 6. TigerVNC :1 â†’ KDE â†’ 8080 + 6080
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true
sudo rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true
echo "[+] Iniciando TigerVNC :1 (KDE Plasma)..."
vncserver :1 -geometry 1366x768 -depth 24 -localhost yes -SecurityTypes None \
    -cleanstale -noreset </dev/null >>"$LOG_DIR/vncserver-kde.log" 2>&1 || true

for i in $(seq 1 20); do
    ss -tlpn 2>/dev/null | grep -q ':5901' && break
    sleep 1
done

for PORT in 8080 6080; do
    if ! ss -tlpn 2>/dev/null | grep -q ":${PORT}"; then
        echo "[+] websockify puerto ${PORT} (KDE)"
        websockify -D --web /usr/share/novnc "$PORT" localhost:5901 2>/dev/null || true
    fi
done

export DISPLAY=:1
nohup vncconfig -nowin </dev/null >/dev/null 2>&1 &
if command -v autocutsel >/dev/null 2>&1; then
    autocutsel -fork 2>/dev/null || true
    autocutsel -selection CLIPBOARD -fork 2>/dev/null || true
fi

# 7. TigerVNC :2 â†’ Antigravity 2.0 â†’ 4000
rm -f /tmp/.X2-lock /tmp/.X11-unix/X2 2>/dev/null || true
sudo rm -f /tmp/.X2-lock /tmp/.X11-unix/X2 2>/dev/null || true

mkdir -p "$HOME/.vnc"
cat > "$HOME/.vnc/xstartup-ide" << 'XSTARTUP'
#!/bin/bash
unset SESSION_MANAGER DBUS_SESSION_BUS_ADDRESS
export DISPLAY=":2"
export XDG_CURRENT_DESKTOP=Antigravity
export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true
command -v autocutsel >/dev/null 2>&1 && { autocutsel -fork 2>/dev/null; autocutsel -selection CLIPBOARD -fork 2>/dev/null; } || true
command -v openbox >/dev/null 2>&1 && openbox & sleep 1
[ -f "/usr/share/wallpapers/playcode-wallpaper.jpg" ] && command -v feh >/dev/null 2>&1 && feh --bg-fill /usr/share/wallpapers/playcode-wallpaper.jpg 2>/dev/null &
while true; do
    if [ -x /usr/local/bin/antigravity ]; then
        /usr/local/bin/antigravity --no-sandbox --disable-gpu --disable-dev-shm-usage
    elif [ -x /opt/antigravity/antigravity ]; then
        /opt/antigravity/antigravity --no-sandbox --disable-gpu --disable-dev-shm-usage
    else
        sleep 5
    fi
    sleep 2
done
XSTARTUP
chmod +x "$HOME/.vnc/xstartup-ide"

echo "[+] Iniciando TigerVNC :2 (Antigravity 2.0 Hub)..."
vncserver :2 -geometry 1366x768 -depth 24 -localhost yes -SecurityTypes None \
    -cleanstale -noreset -xstartup "$HOME/.vnc/xstartup-ide" \
    </dev/null >>"$LOG_DIR/vncserver-ide.log" 2>&1 || true

for i in $(seq 1 20); do
    ss -tlpn 2>/dev/null | grep -q ':5902' && break
    sleep 1
done

if ! ss -tlpn 2>/dev/null | grep -q ':4000'; then
    echo "[+] websockify puerto 4000 (Antigravity 2.0)"
    websockify -D --web /usr/share/novnc 4000 localhost:5902 2>/dev/null || true
fi

# 7b. TigerVNC :3 -> Landing Page Play Code Laboratorio IA (Google AI Studio) -> 5000
if [ ! -f "$HOME/Documents/landing-laboratorio-ia.html" ]; then
    mkdir -p "$HOME/Documents" "$HOME/Documentos" 2>/dev/null || true
    if [ -f "$WS_DIR/assets/landing-laboratorio-ia.html" ]; then
        cp -f "$WS_DIR/assets/landing-laboratorio-ia.html" "$HOME/Documents/landing-laboratorio-ia.html" 2>/dev/null || true
        cp -f "$WS_DIR/assets/landing-laboratorio-ia.html" "$HOME/Documentos/landing-laboratorio-ia.html" 2>/dev/null || true
    fi
fi

if [ ! -f "$HOME/.vnc/xstartup-landing" ]; then
    mkdir -p "$HOME/.vnc" 2>/dev/null || true
    cat > "$HOME/.vnc/xstartup-landing" << 'XLANDING'
#!/bin/bash
unset SESSION_MANAGER DBUS_SESSION_BUS_ADDRESS
export DISPLAY=":3"
export XDG_CURRENT_DESKTOP=Openbox
export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true

command -v autocutsel >/dev/null 2>&1 && { autocutsel -fork 2>/dev/null; autocutsel -selection CLIPBOARD -fork 2>/dev/null; } || true
command -v openbox >/dev/null 2>&1 && openbox &
sleep 1

LANDING_PAGE="file:///home/codespace/Documents/landing-laboratorio-ia.html"

while true; do
    google-chrome \
        --no-sandbox \
        --disable-gpu \
        --disable-dev-shm-usage \
        --no-first-run \
        --no-default-browser-check \
        --disable-session-crashed-bubble \
        --disable-infobars \
        --start-maximized \
        --app="$LANDING_PAGE"
    sleep 2
done
XLANDING
    chmod +x "$HOME/.vnc/xstartup-landing" 2>/dev/null || true
fi

rm -f /tmp/.X3-lock /tmp/.X11-unix/X3 2>/dev/null || true
sudo rm -f /tmp/.X3-lock /tmp/.X11-unix/X3 2>/dev/null || true

if [ -x "$HOME/.vnc/xstartup-landing" ]; then
    echo "[+] Iniciando TigerVNC :3 (Play Code Laboratorio IA / Google AI Studio)..."
    vncserver :3 -geometry 1366x768 -depth 24 -localhost yes -SecurityTypes None \
        -cleanstale -noreset -xstartup "$HOME/.vnc/xstartup-landing" \
        </dev/null >>"$LOG_DIR/vncserver-landing.log" 2>&1 || true

    for i in $(seq 1 20); do
        ss -tlpn 2>/dev/null | grep -q ':5903' && break
        sleep 1
    done

    if ! ss -tlpn 2>/dev/null | grep -q ':5000'; then
        echo "[+] websockify puerto 5000 (Play Code Laboratorio IA)"
        websockify -D --web /usr/share/novnc 5000 localhost:5903 2>/dev/null || true
    fi
fi


# 8. ttyd â†’ puerto 3000
sudo tee /usr/local/bin/agy-web-session >/dev/null << 'SESSION'
#!/usr/bin/env bash
WS_DIR="$(find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1)"
[ -z "$WS_DIR" ] && WS_DIR="/workspaces/kde-lite-ubuntu"
cd "$WS_DIR" 2>/dev/null || cd "$HOME"
export TERM=xterm-256color LANG=C.UTF-8 LC_ALL=C.UTF-8

show_welcome_banner() {
    clear
    printf "\n"
    printf "  \033[48;2;0;26;65m                            \033[0m\n"
    printf "  \033[48;2;0;26;65m                \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m         \033[0m   \033[1;38;2;255;165;20mPLAY CODE\033[0m\n"
    printf "  \033[48;2;0;26;65m   \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m       \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m    \033[38;2;255;115;0m\xe2\x96\x80\xe2\x96\x88\xe2\x96\x88\xe2\x96\x84\033[48;2;0;26;65m   \033[0m   \033[1;97mLaboratorio de IA\033[0m\n"
    printf "  \033[48;2;0;26;65m \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m        \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m         \033[38;2;255;115;0m\xe2\x96\x80\xe2\x96\x88\xe2\x96\x84\033[48;2;0;26;65m \033[0m   \033[38;2;135;155;180mGoogle Antigravity 2.0 CLI Hub\033[0m\n"
    printf "  \033[48;2;0;26;65m \033[38;2;255;115;0m\xe2\x96\x80\xe2\x96\x88\xe2\x96\x84\033[48;2;0;26;65m      \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m           \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m \033[0m   \033[38;2;45;65;95m\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\033[0m\n"
    printf "  \033[48;2;0;26;65m   \033[38;2;255;115;0m\xe2\x96\x80\xe2\x96\x88\xe2\x96\x88\xe2\x96\x84\033[48;2;0;26;65m \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m          \033[38;2;255;115;0m\xe2\x96\x84\xe2\x96\x88\xe2\x96\x88\xe2\x96\x80\033[48;2;0;26;65m   \033[0m   \033[38;2;88;166;255m\xe2\x97\x86 Directorio:\033[0m \033[38;2;135;155;180m%s\033[0m\n" "$WS_DIR"
    printf "  \033[48;2;0;26;65m       \033[38;2;255;115;0m\xe2\x96\x80\xe2\x96\x80\033[48;2;0;26;65m                   \033[0m\n"
    printf "  \033[48;2;0;26;65m                            \033[0m\n\n"
}

show_welcome_banner
AGY_BIN="$(command -v agy 2>/dev/null || echo '')"
[ -z "$AGY_BIN" ] && [ -x /usr/local/bin/agy ] && AGY_BIN=/usr/local/bin/agy
if [ -n "$AGY_BIN" ] && [ -x "$AGY_BIN" ]; then
    while true; do
        "$AGY_BIN" --add-dir="$WS_DIR" --dangerously-skip-permissions "$@"
        printf '\033[1;33m[!] Sesi\xc3\xb3n finalizada. ENTER para reiniciar...\033[0m\n'
        read -r
        show_welcome_banner
    done
else
    echo -e "\033[1;31m[-] agy no encontrado. Iniciando bash...\033[0m"
    exec bash
fi
SESSION
sudo chmod +x /usr/local/bin/agy-web-session

if ! ss -tlpn 2>/dev/null | grep -q ':3000'; then
    echo "[+] Iniciando ttyd puerto 3000..."
    setsid nohup /usr/local/bin/ttyd \
        --port 3000 --writable \
        -t disableLeaveAlert=true \
        -t titleFixed='Google Antigravity 2.0 - PlayCode' \
        -t fontSize=15 \
        -t fontFamily='JetBrains Mono, Menlo, Consolas, monospace' \
        -t 'theme={"background":"#141618","foreground":"#f0f6fc","cursor":"#58a6ff"}' \
        /usr/local/bin/agy-web-session \
        </dev/null >>"$LOG_DIR/ttyd.log" 2>&1 &
    for i in $(seq 1 10); do
        ss -tlpn 2>/dev/null | grep -q ':3000' && break
        sleep 1
    done
fi

# 9. Supervisor de puertos publicos
if [ -n "${CODESPACE_NAME:-}" ]; then
    ENSURE_BIN="/usr/local/bin/ensure-ports-public.sh"
    [ ! -f "$ENSURE_BIN" ] && ENSURE_BIN="$WS_DIR/scripts/ensure-ports-public.sh"
    if [ -f "$ENSURE_BIN" ]; then
        echo "[+] Lanzando supervisor de puertos..."
        setsid nohup bash "$ENSURE_BIN" --daemon >"$LOG_DIR/ensure-ports.log" 2>&1 &
    fi
fi

CS="${CODESPACE_NAME:-codespace}"
echo "=========================================================="
echo " [!] Listo! URLs de acceso:"
echo "  * KDE       : https://${CS}-8080.app.github.dev/vnc.html"
echo "  * Antigrav  : https://${CS}-4000.app.github.dev/vnc.html"
echo "  * AI Studio : https://${CS}-5000.app.github.dev/vnc.html"
echo "  * CLI       : https://${CS}-3000.app.github.dev/"
echo "=========================================================="

# 10. Panel de Control de Servicios (service-control.py — puerto 9000)
SERVICE_CTRL="$WS_DIR/scripts/service-control.py"
if [ -f "$SERVICE_CTRL" ]; then
    pkill -f "service-control.py" 2>/dev/null || true
    sleep 1
    # Al arrancar, limpiar flags .disabled — todo debe encenderse limpio
    rm -f /tmp/.service-kde.disabled /tmp/.service-ide.disabled /tmp/.service-aistudio.disabled /tmp/.service-cli.disabled 2>/dev/null || true
    echo "[+] Iniciando Panel de Control API (puerto 9000)..."
    setsid nohup python3 "$SERVICE_CTRL" >> "$LOG_DIR/service-control.log" 2>&1 &
fi
