#!/usr/bin/env bash
set -euo pipefail

echo "=========================================================="
echo " Instalación de KDE Plasma Minimal + TigerVNC + noVNC"
echo "=========================================================="

export DEBIAN_FRONTEND=noninteractive

LOCK_FILE="/tmp/.install-desktop.lock"
DONE_FILE="/tmp/.install-desktop.done"

# Si ya hay otra instancia ejecutando la instalación, esperar a que termine
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        echo "[!] Ya hay una instalación en curso (PID: $PID). Esperando a que finalice..."
        while kill -0 "$PID" 2>/dev/null; do
            sleep 3
        done
        echo "[+] La instalación previa ha concluido."
        exit 0
    fi
fi

# Registrar PID actual en archivo lock
echo "$$" > "$LOCK_FILE"
cleanup() {
    rm -f "$LOCK_FILE"
}
trap cleanup EXIT

echo "debconf debconf/frontend select Noninteractive" | sudo debconf-set-selections 2>/dev/null || true

wait_for_apt_lock() {
    local max_wait=180
    local waited=0
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
          fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
          fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        echo "[...] Esperando a que otros procesos del sistema liberen APT/dpkg ($waited seg)..."
        sleep 3
        waited=$((waited + 3))
        if [ "$waited" -ge "$max_wait" ]; then
            echo "[!] Advertencia: Tiempo de espera agotado para el bloqueo de APT. Intentando continuar..."
            break
        fi
    done
}

# Comprobar si los paquetes base ya están instalados para evitar demoras innecesarias
if ! command -v startplasma-x11 >/dev/null 2>&1 || \
   ! command -v vncserver >/dev/null 2>&1 || \
   [ ! -d "/usr/share/novnc" ] || \
   ! command -v autocutsel >/dev/null 2>&1; then

    echo "[1/4] Actualizando lista de paquetes e instalando dependencias..."
    wait_for_apt_lock
    sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
    wait_for_apt_lock
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        kde-plasma-desktop \
        kwin-x11 \
        konsole \
        dolphin \
        tigervnc-standalone-server \
        tigervnc-common \
        novnc \
        websockify \
        autocutsel \
        xclip \
        dbus-x11 \
        x11-xserver-utils \
        xterm \
        curl \
        wget \
        xdotool \
        daemonize
else
    echo "[1/4] Dependencias base (KDE, TigerVNC, noVNC, autocutsel) ya instaladas. Omitiendo apt-get."
fi

if ! command -v google-chrome >/dev/null 2>&1; then
    echo "[+] Instalando Google Chrome oficial..."
    wget -q -O /tmp/google-chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y /tmp/google-chrome.deb
    rm -f /tmp/google-chrome.deb
    mkdir -p "$HOME/Desktop"
    cp /usr/share/applications/google-chrome.desktop "$HOME/Desktop/" 2>/dev/null || true
    chmod +x "$HOME/Desktop/google-chrome.desktop" 2>/dev/null || true
fi

# Optimizar Chrome para contenedores y evitar errores SIGILL con WebAssembly/Gemini
sudo sed -i 's|exec -a "$0" "$HERE/chrome" "$@"|exec -a "$0" "$HERE/chrome" --disable-dev-shm-usage --disable-features=WebAssemblySIMD "$@"|' /opt/google/chrome/google-chrome 2>/dev/null || true
sudo mount -o remount,size=2G /dev/shm 2>/dev/null || true

# Descargar e instalar Google Antigravity IDE (2.5.5) oficial
if [ ! -d "/opt/antigravity-ide" ]; then
    echo "[+] Instalando Google Antigravity IDE (v2.5.5)..."
    sudo mkdir -p /opt/antigravity-ide
    curl -sL "https://edgedl.me.gvt1.com/edgedl/release2/j0qc3/antigravity/stable/2.5.5-4923483625488384/linux-x64/Antigravity%20IDE.tar.gz" | sudo tar -xz -C /opt/antigravity-ide --strip-components=1
    sudo chown -R root:root /opt/antigravity-ide
    sudo chmod -R a+rX /opt/antigravity-ide
fi

# Configurar wrappers y accesos directos
echo "[+] Configurando wrappers y accesos directos de Antigravity IDE..."
sudo bash -c 'cat << "EOF" > /usr/local/bin/antigravity-ide
#!/usr/bin/env bash
unset ELECTRON_RUN_AS_NODE
export DISPLAY="${DISPLAY:-:1}"
if [ $# -eq 0 ]; then
    set -- "/workspaces/linux-kde-lite"
fi
exec /opt/antigravity-ide/bin/antigravity-ide \
    --disable-gpu \
    --disable-dev-shm-usage \
    --no-sandbox \
    "$@"
EOF
chmod +x /usr/local/bin/antigravity-ide'

sudo ln -sf /usr/local/bin/antigravity-ide /usr/local/bin/antigravity

# Descargar e instalar Google Antigravity CLI (agy)
if ! command -v agy >/dev/null 2>&1 && [ ! -f "/usr/local/bin/agy" ]; then
    echo "[+] Instalando Google Antigravity CLI (agy)..."
    TMP_DIR=$(mktemp -d)
    if curl -sL "https://storage.googleapis.com/antigravity-public/antigravity-cli/1.2.0-5210873191596032/linux-x64/cli_linux_x64.tar.gz" | tar -xz -C "$TMP_DIR" 2>/dev/null; then
        sudo mv "$TMP_DIR/antigravity" /usr/local/bin/agy
        sudo chmod +x /usr/local/bin/agy
        mkdir -p /home/codespace/.gemini/bin 2>/dev/null || true
        ln -sf /usr/local/bin/agy /home/codespace/.gemini/bin/agy 2>/dev/null || true
    fi
    rm -rf "$TMP_DIR" 2>/dev/null || true
fi
if [ -f "/usr/local/bin/agy" ] && [ ! -f "/home/codespace/.gemini/bin/agy" ]; then
    mkdir -p /home/codespace/.gemini/bin 2>/dev/null || true
    ln -sf /usr/local/bin/agy /home/codespace/.gemini/bin/agy 2>/dev/null || true
fi

# Descargar e instalar ttyd (Web Terminal para Antigravity 2.0 Web Hub en Puerto 3000)
if ! command -v ttyd >/dev/null 2>&1 && [ ! -f "/usr/local/bin/ttyd" ]; then
    echo "[+] Instalando ttyd (Web Terminal)..."
    sudo curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.x86_64" 2>/dev/null || true
    sudo chmod +x /usr/local/bin/ttyd 2>/dev/null || true
fi

# Instalar script de sesión interactiva de Antigravity
sudo tee /usr/local/bin/agy-web-session >/dev/null << 'EOF'
#!/usr/bin/env bash
cd /workspaces/linux-kde-lite 2>/dev/null || cd "$HOME"
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
echo -e "  ${C5} ▀██▄      ▄█▀        ▄██▀    ${R}  ${MUTED}Directorio:${R} ${CYAN}/workspaces/linux-kde-lite${R}"
echo -e "  ${C6}  ▀██▄    ▄█▀        ▄██▀     ${R}  ${DIVIDER}──────────────────────────────────────────${R}"
echo -e "  ${C7}   ▀██▄   █▀        ▄██▀      ${R}  ${CYAN}💡 Tip:${R} ${MUTED}Escribe tus instrucciones para comenzar${R}"
echo ""

AGY_BIN="/usr/local/bin/agy"
[ ! -f "$AGY_BIN" ] && [ -f "/home/codespace/.gemini/bin/agy" ] && AGY_BIN="/home/codespace/.gemini/bin/agy"
[ ! -f "$AGY_BIN" ] && AGY_BIN="$(command -v agy || echo "")"

if [ -n "$AGY_BIN" ] && [ -x "$AGY_BIN" ]; then
    while true; do
        "$AGY_BIN" --add-dir="/workspaces/linux-kde-lite" --dangerously-skip-permissions "$@"
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

# Configurar iconos y entradas de escritorio
mkdir -p "$HOME/Desktop"
[ -f "/opt/antigravity-ide/resources/app/resources/linux/code.png" ] && sudo cp /opt/antigravity-ide/resources/app/resources/linux/code.png /usr/share/pixmaps/antigravity-ide.png 2>/dev/null || true

sudo bash -c 'cat << "EOF" > /usr/share/applications/antigravity-ide.desktop
[Desktop Entry]
Name=Antigravity IDE
Comment=Google Antigravity Code Editor
Exec=/usr/local/bin/antigravity-ide %F
Icon=/usr/share/pixmaps/antigravity-ide.png
Type=Application
StartupNotify=false
StartupWMClass=Antigravity-ide
Categories=Development;IDE;TextEditor;
EOF'

sudo bash -c 'cat << "EOF" > /usr/share/applications/antigravity-web.desktop
[Desktop Entry]
Name=Antigravity Web Hub (Puerto 3000)
Comment=Google Antigravity 2.0 Web UI
Exec=google-chrome --new-window http://localhost:3000
Icon=/usr/share/pixmaps/antigravity-ide.png
Type=Application
StartupNotify=false
Categories=Development;IDE;
EOF'

cp /usr/share/applications/antigravity-ide.desktop "$HOME/Desktop/" 2>/dev/null || true
chmod +x "$HOME/Desktop/antigravity-ide.desktop" 2>/dev/null || true
cp /usr/share/applications/antigravity-web.desktop "$HOME/Desktop/" 2>/dev/null || true
chmod +x "$HOME/Desktop/antigravity-web.desktop" 2>/dev/null || true

# Configurar política de Google Chrome para abrir Antigravity Web Hub por defecto
sudo mkdir -p /etc/opt/chrome/policies/managed
sudo bash -c 'cat << "EOF" > /etc/opt/chrome/policies/managed/antigravity.json
{
  "RestoreOnStartup": 4,
  "RestoreOnStartupURLs": [
    "http://localhost:3000"
  ],
  "HomepageLocation": "http://localhost:3000",
  "HomepageIsNewTabPage": false,
  "ShowHomeButton": true,
  "BookmarkBarEnabled": true,
  "ManagedBookmarks": [
    {
      "name": "Antigravity 2.0 Web Hub",
      "url": "http://localhost:3000"
    },
    {
      "name": "PlayCode Campus LMS",
      "url": "https://edu.playcode.com.ar"
    }
  ]
}
EOF'

# Configurar wrapper inteligente de xdg-open para soportar autenticaciones OAuth y Chrome en KDE
[ ! -f "/usr/bin/xdg-open.orig" ] && sudo cp /usr/bin/xdg-open /usr/bin/xdg-open.orig 2>/dev/null || true
sudo bash -c 'cat << "EOF" > /usr/bin/xdg-open
#!/usr/bin/env bash
URL="$1"
[ -z "$URL" ] && exit 0

echo "[$(date -u)] xdg-open called with: $URL" >> /tmp/xdg-open.log
echo "$URL" > /tmp/last_oauth_url.txt
echo "$URL" > /home/codespace/.vnc/last_oauth_url.txt 2>/dev/null || true
chmod 666 /tmp/last_oauth_url.txt /tmp/xdg-open.log /home/codespace/.vnc/last_oauth_url.txt 2>/dev/null || true

# Detect active KDE DBUS if not present in environment
if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then
    KDE_PID=$(pgrep -f "plasmashell" | head -n 1)
    if [ -n "$KDE_PID" ] && [ -r "/proc/$KDE_PID/environ" ]; then
        KDE_DBUS=$(tr "\0" "\n" < "/proc/$KDE_PID/environ" | grep "^DBUS_SESSION_BUS_ADDRESS=")
        [ -n "$KDE_DBUS" ] && export "$KDE_DBUS"
    fi
fi

export DISPLAY="${DISPLAY:-:1}"

if command -v google-chrome >/dev/null 2>&1; then
    nohup /usr/bin/google-chrome "$URL" >/dev/null 2>&1 &
fi

exit 0
EOF
chmod +x /usr/bin/xdg-open'

# Redirigir helpers de navegador de VS Code Remote a xdg-open
for b in /vscode/bin/linux-x64/*/bin/helpers/browser.sh; do
    [ -f "$b" ] || continue
    [ ! -f "${b}.orig" ] && sudo cp "$b" "${b}.orig" 2>/dev/null || true
    sudo bash -c "cat << 'EOF' > '$b'
#!/usr/bin/env bash
exec /usr/bin/xdg-open \"\$@\"
EOF
chmod +x '$b'"
done

echo "[2/4] Configurando entorno VNC y credenciales..."
echo "$USER:$USER" | sudo chpasswd 2>/dev/null || true
mkdir -p "$HOME/.vnc"

cat << 'EOF' > "$HOME/.vnc/xstartup"
#!/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export XDG_SESSION_TYPE=x11
export XDG_CURRENT_DESKTOP=KDE
export DESKTOP_SESSION=plasma
export KDE_FULL_SESSION=true
export QT_QPA_PLATFORM=xcb

export XDG_RUNTIME_DIR="/tmp/runtime-${USER:-codespace}"
mkdir -p "$XDG_RUNTIME_DIR" 2>/dev/null || true
chmod 0700 "$XDG_RUNTIME_DIR" 2>/dev/null || true

# Desactivar efectos 3D de composición para máxima fluidez y menor consumo en VNC
if command -v kwriteconfig5 >/dev/null 2>&1; then
    kwriteconfig5 --file kwinrc --group Compositing --key Enabled false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Autolock false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key LockOnResume false 2>/dev/null || true
    kwriteconfig5 --file kscreenlockerrc --group Daemon --key Timeout 0 2>/dev/null || true
fi

xset s off 2>/dev/null || true
xset s noblank 2>/dev/null || true

[ -r "$HOME/.Xresources" ] && xrdb "$HOME/.Xresources"

# Sincronización de portapapeles bidireccional (VNC <-> X11 <-> Aplicaciones)
if command -v vncconfig >/dev/null 2>&1; then
    vncconfig -nowin &
fi
if command -v autocutsel >/dev/null 2>&1; then
    autocutsel -fork
    autocutsel -selection CLIPBOARD -fork
fi

# Ejecutar KDE Plasma bajo bus de sesión D-Bus
if command -v dbus-run-session >/dev/null 2>&1; then
    exec dbus-run-session -- startplasma-x11
else
    if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then
        eval $(dbus-launch --sh-syntax --exit-with-session)
    fi
    exec startplasma-x11
fi
EOF

chmod +x "$HOME/.vnc/xstartup"

echo "[3/4] Configurando opciones predeterminadas de TigerVNC..."
cat << 'EOF' > "$HOME/.vnc/config"
geometry=1280x800
depth=24
localhost=yes
EOF

echo "[4/4] Optimizando acceso web de noVNC y portapapeles..."
# Configurar redirección automática con autoconnect y escalado dinámico
if [ -d "/usr/share/novnc" ]; then
    sudo bash -c 'cat << "EOF" > /usr/share/novnc/index.html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0; url=vnc.html?autoconnect=true&resize=remote">
    <title>KDE Plasma Lite</title>
</head>
<body style="background-color: #1b1e20; color: #fff; font-family: sans-serif; text-align: center; padding-top: 50px;">
    <h2>Iniciando KDE Plasma Desktop...</h2>
    <p>Si no eres redirigido automáticamente, <a href="vnc.html?autoconnect=true&resize=remote" style="color: #3daee9;">haz clic aquí</a>.</p>
</body>
</html>
EOF'

    # Habilitar integración automática del portapapeles en noVNC
    sudo python3 -c '
UI_JS = "/usr/share/novnc/app/ui.js"
try:
    with open(UI_JS, "r") as f:
        content = f.read()
    if "noVNC_clipboard_text\x27).addEventListener(\x27input\x27" not in content:
        content = content.replace(
            "document.getElementById(\"noVNC_clipboard_text\")\n            .addEventListener(\x27change\x27, UI.clipboardSend);",
            "document.getElementById(\"noVNC_clipboard_text\").addEventListener(\x27change\x27, UI.clipboardSend);\n        document.getElementById(\"noVNC_clipboard_text\").addEventListener(\x27input\x27, UI.clipboardSend);\n\n        window.addEventListener(\x27paste\x27, (e) => {\n            if (document.activeElement && document.activeElement.id === \"noVNC_clipboard_text\") return;\n            const p = e.clipboardData ? e.clipboardData.getData(\"text\") : null;\n            if (p && UI.rfb) { document.getElementById(\"noVNC_clipboard_text\").value = p; UI.rfb.clipboardPasteFrom(p); }\n        });\n        window.addEventListener(\x27focus\x27, () => {\n            if (navigator.clipboard && navigator.clipboard.readText && UI.rfb) {\n                navigator.clipboard.readText().then(t => { if (t && t !== document.getElementById(\"noVNC_clipboard_text\").value) { document.getElementById(\"noVNC_clipboard_text\").value = t; UI.rfb.clipboardPasteFrom(t); } }).catch(() => {});\n            }\n        });"
        )
        content = content.replace(
            "document.getElementById(\x27noVNC_clipboard_text\x27).value = e.detail.text;\n        Log.Debug(\"<< UI.clipboardReceive\");",
            "document.getElementById(\x27noVNC_clipboard_text\x27).value = e.detail.text;\n        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(e.detail.text).catch(() => {}); }\n        Log.Debug(\"<< UI.clipboardReceive\");"
        )
        with open(UI_JS, "w") as f:
            f.write(content)
except Exception as err:
    print(f"Clipboard patch skipped: {err}")
' 2>/dev/null || true
fi

# Instalar scripts globales en /usr/local/bin
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/start-desktop.sh" ]; then
    sudo cp "$SCRIPT_DIR/start-desktop.sh" /usr/local/bin/start-desktop.sh
    sudo chmod +x /usr/local/bin/start-desktop.sh
    sudo ln -sf /usr/local/bin/start-desktop.sh /usr/local/bin/start-desktop
fi
if [ -f "$SCRIPT_DIR/ensure-ports-public.sh" ]; then
    sudo cp "$SCRIPT_DIR/ensure-ports-public.sh" /usr/local/bin/ensure-ports-public.sh
    sudo chmod +x /usr/local/bin/ensure-ports-public.sh
    sudo ln -sf /usr/local/bin/ensure-ports-public.sh /usr/local/bin/ensure-ports-public
fi

touch "$DONE_FILE" 2>/dev/null || true

echo "=========================================================="
echo " ¡Instalación completada con éxito!"
echo " Para iniciar el escritorio ejecuta: ./scripts/start-desktop.sh"
echo "=========================================================="

