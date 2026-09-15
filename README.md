# kde-lite-ubuntu

Entorno de escritorio **KDE Plasma Minimal** ligero para **GitHub Codespaces**, accesible directamente desde cualquier navegador web utilizando **TigerVNC** y **noVNC** (HTML5). Incluye Google Chrome, Antigravity IDE 2.0 Web y Antigravity CLI preinstalados.

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/playcodeprint3d-crypto/kde-lite-ubuntu)

> **Para alumnos / nuevos usuarios:**  
> Simplemente haz clic en el botón **Open in GitHub Codespaces** de arriba o ingresa a [https://codespaces.new/playcodeprint3d-crypto/kde-lite-ubuntu](https://codespaces.new/playcodeprint3d-crypto/kde-lite-ubuntu).  
> GitHub creará tu propia máquina virtual aislada en la nube y configurará el escritorio automáticamente. Al iniciar, se abrirá una pestaña con tu escritorio Linux listo para usar.

---

## 🚀 Inicio Rápido

### 1. Instalación (Solo la primera vez)
Ejecuta el script de instalación para descargar KDE Plasma Minimal, utilidades básicas (Konsole, Dolphin), TigerVNC y noVNC:

```bash
./scripts/install-desktop.sh
```

### 2. Iniciar el escritorio
Para levantar el servidor gráfico virtual y el cliente web:

```bash
./scripts/start-desktop.sh
```

### 3. Conexión desde el navegador
1. Abre la pestaña **Ports** (Puertos) en la barra inferior de VS Code / GitHub Codespaces.
2. Localiza el servicio que deseas abrir:
   * **`8080`** (o `6080`): **Escritorio KDE Plasma Lite (noVNC)** — sesión gráfica completa en navegador.
   * **`3000`**: **Antigravity 2.0 Web Hub** — interfaz web oficial de asistencia IA y Vibecoding (`https://<codespace>-3000.app.github.dev/`).
3. Haz clic en el icono del **globo terráqueo** (*Open in Browser*) o abre la URL pública asignada.
4. ¡Listo! Accederás directamente a tu sesión.

---

## 🛠️ Comandos de Gestión

* **Ver estado del entorno:**
  ```bash
  ./scripts/status-desktop.sh
  ```

* **Detener el escritorio:**
  ```bash
  ./scripts/stop-desktop.sh
  ```

---

## ⚙️ Características y Optimizaciones
* **KDE Plasma Minimal:** Incluye componentes esenciales sin bloatware ni suites pesadas innecesarias.
* **Composición 3D desactivada:** Optimizado para baja latencia y alta fluidez en streaming web.
* **noVNC HTML5:** No requiere clientes VNC externos en tu máquina local.
* **Ajuste dinámico:** La resolución del escritorio se adapta a la ventana de tu navegador.
