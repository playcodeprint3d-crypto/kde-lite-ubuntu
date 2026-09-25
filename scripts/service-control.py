#!/usr/bin/env python3
# =============================================================================
# service-control.py — PlayCode Service Control API
# Puerto: 9000 | Corre dentro del GitHub Codespace
#
# Expone una mini-API HTTP para encender/apagar servicios individuales:
#   GET  /status                      → JSON con estado de cada servicio
#   POST /service/{kde|ide|cli}/stop  → Para el servicio indicado
#   POST /service/{kde|ide|cli}/start → Arranca el servicio indicado
#
# Los archivos /tmp/.service-{name}.disabled previenen que el daemon
# ensure-ports-public.sh reinicie servicios intencionalmente apagados.
# =============================================================================

import http.server
import json
import os
import subprocess
import re
import signal
import sys
from urllib.parse import urlparse

# ──────────────────────────────────────────────────────────────────────────────
# Configuración
# ──────────────────────────────────────────────────────────────────────────────

PORT = 9000
HOME = os.environ.get("HOME", "/home/codespace")
VNC_LOG_DIR = os.path.join(HOME, ".vnc")
XSTARTUP_IDE = os.path.join(HOME, ".vnc", "xstartup-ide")

SERVICES = {
    "kde": {
        "label": "KDE Plasma",
        "check_ports": [5901, 8080, 6080],
        "primary_port": 5901,
    },
    "ide": {
        "label": "Antigravity 2.0 IDE",
        "check_ports": [5902, 4000],
        "primary_port": 5902,
    },
    "cli": {
        "label": "Terminal CLI (ttyd)",
        "check_ports": [3000],
        "primary_port": 3000,
    },
}

DISABLED_FLAG_PATTERN = "/tmp/.service-{name}.disabled"


# ──────────────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────────────

def run(cmd, timeout=15):
    """Ejecuta un comando shell y retorna (returncode, stdout, stderr)."""
    try:
        result = subprocess.run(
            cmd, shell=True, capture_output=True, text=True, timeout=timeout
        )
        return result.returncode, result.stdout, result.stderr
    except subprocess.TimeoutExpired:
        return -1, "", "timeout"
    except Exception as e:
        return -1, "", str(e)


def port_is_listening(port):
    """Verifica si un puerto TCP local está escuchando."""
    code, out, _ = run(f"ss -tlpn 2>/dev/null | grep -q ':{port}'")
    return code == 0


def service_state(name):
    """Retorna 'running' o 'stopped' según si el puerto principal responde."""
    svc = SERVICES.get(name)
    if not svc:
        return "unknown"
    if port_is_listening(svc["primary_port"]):
        return "running"
    return "stopped"


def set_disabled_flag(name, disabled: bool):
    path = DISABLED_FLAG_PATTERN.format(name=name)
    if disabled:
        run(f"touch {path}")
    else:
        run(f"rm -f {path}")


def get_workspaces_dir():
    code, out, _ = run(
        "find /workspaces -mindepth 1 -maxdepth 1 -type d 2>/dev/null | head -n 1"
    )
    ws = out.strip()
    return ws if ws else "/workspaces/kde-lite-ubuntu"


# ──────────────────────────────────────────────────────────────────────────────
# Stop helpers
# ──────────────────────────────────────────────────────────────────────────────

def stop_kde():
    run("vncserver -kill :1 2>/dev/null")
    run("sleep 1")
    run("pkill -f 'Xtigervnc.*:1' 2>/dev/null")
    run("pkill -f 'websockify.*8080' 2>/dev/null")
    run("pkill -f 'websockify.*6080' 2>/dev/null")


def stop_ide():
    run("vncserver -kill :2 2>/dev/null")
    run("sleep 1")
    run("pkill -f 'Xtigervnc.*:2' 2>/dev/null")
    run("pkill -f 'websockify.*4000' 2>/dev/null")


def stop_cli():
    run("pkill -f 'ttyd.*3000' 2>/dev/null")


STOP_HANDLERS = {"kde": stop_kde, "ide": stop_ide, "cli": stop_cli}


# ──────────────────────────────────────────────────────────────────────────────
# Start helpers
# ──────────────────────────────────────────────────────────────────────────────

def start_kde():
    run("rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null")
    run("sudo rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null")
    log = os.path.join(VNC_LOG_DIR, "vncserver-kde.log")
    run(
        f"setsid nohup vncserver :1 -geometry 1366x768 -depth 24 "
        f"-localhost yes -SecurityTypes None -cleanstale -noreset "
        f"</dev/null >>{log} 2>&1 &"
    )
    # Esperar hasta 20s a que levante
    for _ in range(20):
        if port_is_listening(5901):
            break
        run("sleep 1")
    for port, vnc_port in [(8080, 5901), (6080, 5901)]:
        if not port_is_listening(port):
            run(
                f"websockify -D --web /usr/share/novnc {port} "
                f"localhost:{vnc_port} 2>/dev/null"
            )


def start_ide():
    run("rm -f /tmp/.X2-lock /tmp/.X11-unix/X2 2>/dev/null")
    run("sudo rm -f /tmp/.X2-lock /tmp/.X11-unix/X2 2>/dev/null")
    xstartup = XSTARTUP_IDE
    if not os.path.exists(xstartup):
        # Intentar regenerar desde start-desktop.sh (que lo crea en línea)
        ws = get_workspaces_dir()
        run(f"bash {ws}/scripts/start-desktop.sh 2>/dev/null &")
        return
    log = os.path.join(VNC_LOG_DIR, "vncserver-ide.log")
    run(
        f"setsid nohup vncserver :2 -geometry 1366x768 -depth 24 "
        f"-localhost yes -SecurityTypes None -cleanstale -noreset "
        f"-xstartup {xstartup} </dev/null >>{log} 2>&1 &"
    )
    for _ in range(20):
        if port_is_listening(5902):
            break
        run("sleep 1")
    if not port_is_listening(4000):
        run(
            "websockify -D --web /usr/share/novnc 4000 "
            "localhost:5902 2>/dev/null"
        )


def start_cli():
    ws = get_workspaces_dir()
    log = os.path.join(VNC_LOG_DIR, "ttyd.log")
    run(
        f"setsid nohup /usr/local/bin/ttyd "
        f"--port 3000 --writable "
        f"-t disableLeaveAlert=true "
        f"-t titleFixed='Google Antigravity 2.0 - PlayCode' "
        f"-t fontSize=15 "
        f"-t fontFamily='JetBrains Mono, Menlo, Consolas, monospace' "
        f"-t 'theme={{\"background\":\"#141618\",\"foreground\":\"#f0f6fc\",\"cursor\":\"#58a6ff\"}}' "
        f"/usr/local/bin/agy-web-session "
        f"</dev/null >>{log} 2>&1 &"
    )
    for _ in range(10):
        if port_is_listening(3000):
            break
        run("sleep 1")


START_HANDLERS = {"kde": start_kde, "ide": start_ide, "cli": start_cli}


# ──────────────────────────────────────────────────────────────────────────────
# HTTP Handler
# ──────────────────────────────────────────────────────────────────────────────

class ServiceControlHandler(http.server.BaseHTTPRequestHandler):

    def log_message(self, fmt, *args):
        # Log compacto con timestamp
        print(f"[service-control] {self.address_string()} {fmt % args}")

    def send_json(self, code, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Authorization, Content-Type")
        self.end_headers()

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/status":
            status = {name: service_state(name) for name in SERVICES}
            self.send_json(200, {"ok": True, "services": status})
        elif parsed.path == "/health":
            self.send_json(200, {"ok": True, "pid": os.getpid()})
        else:
            self.send_json(404, {"ok": False, "error": "Not found"})

    def do_POST(self):
        parsed = urlparse(self.path)
        # Espera: /service/{name}/{action}
        match = re.fullmatch(r"/service/(kde|ide|cli)/(start|stop)", parsed.path)
        if not match:
            self.send_json(404, {"ok": False, "error": "Unknown endpoint"})
            return

        name, action = match.group(1), match.group(2)

        try:
            if action == "stop":
                set_disabled_flag(name, True)
                STOP_HANDLERS[name]()
                state = service_state(name)
                self.send_json(200, {"ok": True, "service": name, "state": state})

            elif action == "start":
                set_disabled_flag(name, False)
                START_HANDLERS[name]()
                state = service_state(name)
                self.send_json(200, {"ok": True, "service": name, "state": state})

        except Exception as exc:
            self.send_json(500, {"ok": False, "error": str(exc)})


# ──────────────────────────────────────────────────────────────────────────────
# Main
# ──────────────────────────────────────────────────────────────────────────────

def main():
    os.makedirs(VNC_LOG_DIR, exist_ok=True)

    server = http.server.HTTPServer(("0.0.0.0", PORT), ServiceControlHandler)

    def shutdown(sig, frame):
        print(f"\n[service-control] Señal {sig} recibida, cerrando...")
        server.server_close()
        sys.exit(0)

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    print(f"[service-control] Escuchando en 0.0.0.0:{PORT}")
    print(f"[service-control] Endpoints: GET /status | POST /service/{{kde|ide|cli}}/{{start|stop}}")

    server.serve_forever()


if __name__ == "__main__":
    main()
