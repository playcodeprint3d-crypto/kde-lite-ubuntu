#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Supervisor y Visibilidad Pública de Puertos en GitHub Codespaces
# =============================================================================
# Garantiza que los servicios esenciales (VNC, noVNC, Antigravity 2.0 Web Hub)
# y sus puertos (8080, 6080, 3000) permanezcan SIEMPRE activos y con visibilidad
# PÚBLICA, incluso si el usuario desconecta o cierra la interfaz de VS Code.
# =============================================================================

TARGET_PORTS=(8080 6080 3000 4000)
DAEMON_PID_FILE="/tmp/.ensure-ports-public-daemon.pid"
LOG_DIR="${HOME:-/home/codespace}/.vnc"
mkdir -p "$LOG_DIR" 2>/dev/null || true

IS_DAEMON=false
for arg in "$@"; do
    if [ "$arg" = "--daemon" ] || [ "$arg" = "-d" ]; then
        IS_DAEMON=true
    fi
done

# Función para iniciar Antigravity Web Hub interactivo vía ttyd en puerto 3000
start_antigravity_hub() {
    if [ -x /usr/local/bin/ttyd ] && [ -x /usr/local/bin/agy-web-session ]; then
        echo "[+] [Supervisor] Iniciando Antigravity 2.0 Web Hub en puerto 3000..."
        setsid nohup /usr/local/bin/ttyd \
            --port 3000 \
            --writable \
            -t disableLeaveAlert=true \
            -t titleFixed='Google Antigravity 2.0 Web Hub' \
            -t fontSize=15 \
            -t fontFamily='JetBrains Mono, Menlo, Consolas, monospace' \
            -t 'theme={"background": "#141618", "foreground": "#f0f6fc", "cursor": "#58a6ff"}' \
            /usr/local/bin/agy-web-session </dev/null >>"${LOG_DIR}/antigravity-hub.log" 2>&1 &
    fi
}

# Función para iniciar websockify (noVNC)
start_websockify() {
    local port="$1"
    local vnc_port="${2:-5901}"
    echo "[+] [Supervisor] Iniciando puente web noVNC en puerto ${port}..."
    websockify -D --web /usr/share/novnc "${port}" "localhost:${vnc_port}" 2>/dev/null || true
}

# Función para iniciar TigerVNC
start_vnc() {
    echo "[+] [Supervisor] Iniciando servidor TigerVNC en :1 (puerto 5901)..."
    setsid nohup vncserver :1 -geometry 1366x768 -depth 24 -localhost yes -SecurityTypes None -cleanstale -noreset </dev/null >> "${LOG_DIR}/vncserver.log" 2>&1 || true
}

# Supervisar que los procesos estén escuchando en sus puertos locales
supervise_services() {
    local restarted=false

    # 1. TigerVNC (puerto 5901)
    if ! ss -tlpn 2>/dev/null | grep -E "(:5901\s)" >/dev/null 2>&1; then
        start_vnc
        restarted=true
    fi

    # 2. websockify en puerto 8080
    if ! ss -tlpn 2>/dev/null | grep -E "(:8080\s)" >/dev/null 2>&1; then
        start_websockify 8080 5901
        restarted=true
    fi

    # 3. websockify en puerto 6080
    if ! ss -tlpn 2>/dev/null | grep -E "(:6080\s)" >/dev/null 2>&1; then
        start_websockify 6080 5901
        restarted=true
    fi

    # 4. Antigravity 2.0 Web Hub en puerto 3000
    if ! ss -tlpn 2>/dev/null | grep -E "(:3000\s)" >/dev/null 2>&1; then
        start_antigravity_hub
        restarted=true
    fi

    # 5. TigerVNC en :2 para Antigravity IDE (puerto 5902)
    if ! ss -tlpn 2>/dev/null | grep -E "(:5902\s)" >/dev/null 2>&1; then
        if [ -x "${HOME:-/home/codespace}/.vnc/xstartup-ide" ]; then
            setsid nohup vncserver :2 -geometry 1366x768 -depth 24 -localhost yes -SecurityTypes None -cleanstale -noreset -xstartup "${HOME:-/home/codespace}/.vnc/xstartup-ide" </dev/null >> "${LOG_DIR}/vncserver-ide.log" 2>&1 || true
            restarted=true
        fi
    fi

    # 6. websockify en puerto 4000
    if ! ss -tlpn 2>/dev/null | grep -E "(:4000\s)" >/dev/null 2>&1; then
        start_websockify 4000 5902
        restarted=true
    fi

    if [ "$restarted" = true ]; then
        sleep 2
        check_and_update_ports >/dev/null 2>&1 || true
    fi
}

get_private_ports() {
    local json="$1"
    if command -v jq >/dev/null 2>&1; then
        echo "$json" | jq -r '.[] | select(.visibility != "public") | .sourcePort' 2>/dev/null || true
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c '
import sys, json
try:
    data = json.loads(sys.argv[1])
    for item in data:
        if item.get("visibility") != "public":
            print(item.get("sourcePort", ""))
except Exception:
    pass
' "$json" 2>/dev/null || true
    fi
}

get_port_visibility() {
    local json="$1"
    local target="$2"
    if command -v jq >/dev/null 2>&1; then
        echo "$json" | jq -r ".[] | select(.sourcePort == $target) | .visibility" 2>/dev/null || echo ""
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c '
import sys, json
try:
    data = json.loads(sys.argv[1])
    for item in data:
        if item.get("sourcePort") == int(sys.argv[2]):
            print(item.get("visibility", ""))
            break
except Exception:
    pass
' "$json" "$target" 2>/dev/null || echo ""
    fi
}

check_and_update_ports() {
    if [ -z "${CODESPACE_NAME:-}" ] || ! command -v gh >/dev/null 2>&1; then
        return 0
    fi

    local ports_json
    ports_json=$(gh codespace ports -c "$CODESPACE_NAME" --json sourcePort,visibility 2>/dev/null || echo "[]")

    if [ -z "$ports_json" ] || [ "$ports_json" = "[]" ] || [ "$ports_json" = "null" ]; then
        return 1
    fi

    local to_update=()
    for tp in "${TARGET_PORTS[@]}"; do
        local vis
        vis=$(get_port_visibility "$ports_json" "$tp")
        if [ "$vis" != "public" ]; then
            to_update+=("${tp}:public")
        fi
    done

    if [ ${#to_update[@]} -gt 0 ]; then
        echo "[+] Asegurando visibilidad pública para puertos objetivo: ${to_update[*]}"
        gh codespace ports visibility "${to_update[@]}" -c "$CODESPACE_NAME" >/dev/null 2>&1 || true
    fi

    # Comprobar si todos los puertos objetivo están presentes y públicos
    ports_json=$(gh codespace ports -c "$CODESPACE_NAME" --json sourcePort,visibility 2>/dev/null || echo "[]")
    local all_ready=true
    for tp in "${TARGET_PORTS[@]}"; do
        local vis
        vis=$(get_port_visibility "$ports_json" "$tp")
        if [ "$vis" != "public" ]; then
            all_ready=false
            break
        fi
    done

    if [ "$all_ready" = true ]; then
        return 0
    else
        return 2
    fi
}

# ---------------------------------------------------------------------------
# Modo Daemon Continuo
# ---------------------------------------------------------------------------
if [ "$IS_DAEMON" = true ]; then
    # Evitar múltiples instancias del daemon
    if [ -f "$DAEMON_PID_FILE" ]; then
        OLD_PID=$(cat "$DAEMON_PID_FILE" 2>/dev/null || echo "")
        if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
            if grep -q "ensure-ports-public" "/proc/$OLD_PID/cmdline" 2>/dev/null; then
                echo "[!] Daemon ensure-ports-public ya está en ejecución (PID: $OLD_PID). Saliendo."
                exit 0
            fi
        fi
    fi
    echo "$$" > "$DAEMON_PID_FILE"
    trap 'rm -f "$DAEMON_PID_FILE"' EXIT INT TERM

    echo "[+] Daemon de supervisión activo (PID: $$)..."
    cycle=0
    while true; do
        supervise_services

        # Verificar visibilidad pública cada 10 ciclos (~30 segundos) o en el primer ciclo
        if [ "$cycle" -eq 0 ] || [ $((cycle % 10)) -eq 0 ]; then
            check_and_update_ports >/dev/null 2>&1 || true
            echo "[$(date -u +'%Y-%m-%d %H:%M:%SZ')] Supervisión activa - Puertos 8080, 6080, 3000 verificados" > /tmp/.codespace-heartbeat.log 2>/dev/null || true
        fi

        sleep 3
        cycle=$((cycle + 1))
    done
fi

# ---------------------------------------------------------------------------
# Modo Ejecución Única / Disparador
# ---------------------------------------------------------------------------
# 1. Supervisar servicios inmediatamente
supervise_services

# 2. Comprobar y actualizar visibilidad con reintentos iniciales
MAX_ATTEMPTS=20
attempt=1
while [ "$attempt" -le "$MAX_ATTEMPTS" ]; do
    set +e
    check_and_update_ports
    status=$?
    set -e

    if [ "$status" -eq 0 ]; then
        echo "[+] Confirmado: Todos los puertos del sistema (${TARGET_PORTS[*]}) están públicos."
        break
    fi

    sleep 2
    attempt=$((attempt + 1))
done

# 3. Asegurar que el daemon continuo esté corriendo en segundo plano
DAEMON_RUNNING=false
if [ -f "$DAEMON_PID_FILE" ]; then
    DPID=$(cat "$DAEMON_PID_FILE" 2>/dev/null || echo "")
    if [ -n "$DPID" ] && kill -0 "$DPID" 2>/dev/null; then
        DAEMON_RUNNING=true
    fi
fi

if [ "$DAEMON_RUNNING" = false ]; then
    echo "[+] Iniciando daemon de supervisión en segundo plano..."
    SCRIPT_PATH="$0"
    [ ! -f "$SCRIPT_PATH" ] && SCRIPT_PATH="/usr/local/bin/ensure-ports-public.sh"
    setsid nohup bash "$SCRIPT_PATH" --daemon >"${LOG_DIR}/ensure-ports-public.log" 2>&1 &
fi
