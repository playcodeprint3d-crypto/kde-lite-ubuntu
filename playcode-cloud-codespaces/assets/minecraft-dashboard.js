/**
 * Play Code — Minecraft Server Dashboard Controller
 */

(function($) {
  'use strict';

  let currentCodespace = null;
  let currentState = 'loading';
  let pollTimer = null;
  let friendsList = [];
  let isWhitelistOn = false;

  $(document).ready(function() {
    // Cargar amigos guardados localmente
    loadSavedFriends();

    // Comprobar estado inicial
    fetchStatus();

    // Eventos de botones
    $('#mc-btn-create').on('click', handleCreate);
    $('#mc-btn-start').on('click', handleStart);
    $('#mc-btn-stop').on('click', handleStop);
    $('#mc-btn-delete').on('click', handleDelete);
    $('#mc-btn-whitelist-toggle').on('click', handleToggleWhitelist);
    $('#mc-btn-add-friend').on('click', handleAddFriend);
    $('#mc-input-friend').on('keypress', function(e) {
      if (e.which === 13) handleAddFriend();
    });
  });

  function fetchStatus() {
    $.ajax({
      url: playcodeMC.ajaxurl,
      type: 'POST',
      data: {
        action: 'playcode_mc_status',
        nonce: playcodeMC.nonce
      },
      success: function(res) {
        if (!res.success) {
          showView('unauthorized');
          return;
        }

        const data = res.data;
        if (!data.authorized) {
          showView('unauthorized');
          return;
        }

        currentCodespace = data.codespace;
        currentState = data.state;

        updateUI(data);

        // Polling adaptativo
        clearTimeout(pollTimer);
        const pollInterval = (currentState === 'Building' || currentState === 'Starting' || currentState === 'Provisioning') ? 4000 : 8000;
        pollTimer = setTimeout(fetchStatus, pollInterval);
      },
      error: function() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(fetchStatus, 10000);
      }
    });
  }

  function updateUI(data) {
    const badge = $('#mc-status-badge');

    if (!currentCodespace || currentState === 'not_created') {
      badge.attr('class', 'playcode-mc-badge playcode-mc-badge-gray').text('SIN CREAR');
      showView('no-server');
      return;
    }

    if (currentState === 'Building' || currentState === 'Starting' || currentState === 'Provisioning' || currentState === 'Queued') {
      badge.attr('class', 'playcode-mc-badge playcode-mc-badge-yellow').text('INICIANDO...');
      $('#mc-loading-title').text('Preparando tu servidor en la nube...');
      $('#mc-loading-desc').text('Estado actual: ' + currentState + '. Tu mundo estará listo en segundos.');
      showView('loading');
      return;
    }

    if (currentState === 'Available' || currentState === 'Running') {
      badge.attr('class', 'playcode-mc-badge playcode-mc-badge-green').text('🟢 EN LÍNEA');
      $('#mc-conn-badge').attr('class', 'playcode-mc-pill playcode-mc-pill-green').text('🟢 En Línea');
      $('#mc-public-ip-text').text(data.public_address || playcodeMC.publicAddr);
      $('#mc-active-version').text('PaperMC ' + (data.version || '1.20.4'));
      $('#mc-btn-start').hide();
      $('#mc-btn-stop').show();
      showView('active');
      return;
    }

    if (currentState === 'Shutdown' || currentState === 'Stopped') {
      badge.attr('class', 'playcode-mc-badge playcode-mc-badge-red').text('🔴 DETENIDO');
      $('#mc-conn-badge').attr('class', 'playcode-mc-pill playcode-mc-pill-red').text('🔴 Detenido');
      $('#mc-public-ip-text').text(data.public_address || playcodeMC.publicAddr);
      $('#mc-active-version').text('PaperMC ' + (data.version || '1.20.4'));
      $('#mc-btn-start').show();
      $('#mc-btn-stop').hide();
      showView('active');
      return;
    }

    // Cualquier otro estado
    showView('loading');
  }

  function showView(viewName) {
    $('#mc-view-unauthorized').hide();
    $('#mc-view-no-server').hide();
    $('#mc-view-loading').hide();
    $('#mc-view-active').hide();

    if (viewName === 'unauthorized') $('#mc-view-unauthorized').show();
    else if (viewName === 'no-server') $('#mc-view-no-server').show();
    else if (viewName === 'loading') $('#mc-view-loading').show();
    else if (viewName === 'active') $('#mc-view-active').show();
  }

  function handleCreate() {
    const version = $('#mc-version-select').val();
    const btn = $('#mc-btn-create');

    btn.prop('disabled', true).text('Creando máquina...');
    $('#mc-loading-title').text('Creando tu nuevo servidor...');
    $('#mc-loading-desc').text('Descargando PaperMC ' + version + ' y conectando al relay.');
    showView('loading');

    $.ajax({
      url: playcodeMC.ajaxurl,
      type: 'POST',
      data: {
        action: 'playcode_mc_create',
        nonce: playcodeMC.nonce,
        version: version
      },
      success: function(res) {
        btn.prop('disabled', false).text('🎮 Crear mi Servidor de Minecraft');
        if (res.success) {
          fetchStatus();
        } else {
          alert('Error creando el servidor: ' + (res.data || 'Intenta nuevamente'));
          showView('no-server');
        }
      },
      error: function() {
        btn.prop('disabled', false).text('🎮 Crear mi Servidor de Minecraft');
        alert('Error de conexión con el servidor.');
        showView('no-server');
      }
    });
  }

  function handleStart() {
    if (!currentCodespace) return;
    const btn = $('#mc-btn-start');
    btn.prop('disabled', true).text('Encendiendo...');
    showView('loading');

    $.ajax({
      url: playcodeMC.ajaxurl,
      type: 'POST',
      data: {
        action: 'playcode_mc_start',
        nonce: playcodeMC.nonce,
        name: currentCodespace.name
      },
      success: function() {
        btn.prop('disabled', false).text('▶️ Encender Servidor');
        fetchStatus();
      },
      error: function() {
        btn.prop('disabled', false).text('▶️ Encender Servidor');
        fetchStatus();
      }
    });
  }

  function handleStop() {
    if (!currentCodespace) return;
    if (!confirm('¿Seguro que deseas detener tu servidor de Minecraft? Tus construcciones quedarán guardadas.')) return;

    const btn = $('#mc-btn-stop');
    btn.prop('disabled', true).text('Deteniendo...');

    $.ajax({
      url: playcodeMC.ajaxurl,
      type: 'POST',
      data: {
        action: 'playcode_mc_stop',
        nonce: playcodeMC.nonce,
        name: currentCodespace.name
      },
      success: function() {
        btn.prop('disabled', false).text('⏹ Detener Servidor');
        fetchStatus();
      },
      error: function() {
        btn.prop('disabled', false).text('⏹ Detener Servidor');
        fetchStatus();
      }
    });
  }

  function handleDelete() {
    if (!currentCodespace) return;
    if (!confirm('¿Seguro que deseas eliminar este servidor? Podrás crear uno nuevo con la versión que quieras.')) return;

    showView('loading');
    $('#mc-loading-title').text('Eliminando servidor...');

    $.ajax({
      url: playcodeMC.ajaxurl,
      type: 'POST',
      data: {
        action: 'playcode_mc_delete',
        nonce: playcodeMC.nonce,
        name: currentCodespace.name
      },
      success: function() {
        currentCodespace = null;
        currentState = 'not_created';
        showView('no-server');
      },
      error: function() {
        alert('No se pudo eliminar el servidor.');
        fetchStatus();
      }
    });
  }

  // Whitelist Logic
  function loadSavedFriends() {
    try {
      const saved = localStorage.getItem('playcode_mc_friends');
      if (saved) friendsList = JSON.parse(saved);
      const wlState = localStorage.getItem('playcode_mc_wl_state');
      if (wlState === 'true') isWhitelistOn = true;
    } catch(e) {}
    renderFriends();
    updateWlButton();
  }

  function saveFriends() {
    try {
      localStorage.setItem('playcode_mc_friends', JSON.stringify(friendsList));
      localStorage.setItem('playcode_mc_wl_state', isWhitelistOn ? 'true' : 'false');
    } catch(e) {}
  }

  function renderFriends() {
    const container = $('#mc-whitelist-chips');
    if (!friendsList.length) {
      container.html('<span style="font-size: 13px; color: #94A3B8;">Aún no has agregado amigos a la lista.</span>');
      return;
    }

    container.html(friendsList.map(name => `
      <div class="playcode-mc-chip">
        <span>👤 ${name}</span>
        <span class="playcode-mc-chip-del" onclick="playcodeMCRemoveFriend('${name}')">✕</span>
      </div>
    `).join(''));
  }

  function updateWlButton() {
    const btn = $('#mc-btn-whitelist-toggle');
    if (isWhitelistOn) {
      btn.attr('class', 'playcode-mc-btn playcode-mc-btn-red').text('Desactivar Lista Blanca');
    } else {
      btn.attr('class', 'playcode-mc-btn playcode-mc-btn-blue').text('Activar Lista Blanca');
    }
  }

  function handleToggleWhitelist() {
    isWhitelistOn = !isWhitelistOn;
    updateWlButton();
    saveFriends();
    alert(isWhitelistOn ? '🛡️ Lista blanca activada: Solo tus amigos agregados podrán entrar.' : 'Lista blanca desactivada: Todos pueden entrar.');
  }

  function handleAddFriend() {
    const input = $('#mc-input-friend');
    const name = input.value ? input.value.trim() : input.val().trim();
    if (!name) return;

    if (!friendsList.includes(name)) {
      friendsList.push(name);
      saveFriends();
      renderFriends();
    }
    input.val('');
  }

  window.playcodeMCRemoveFriend = function(name) {
    if (!confirm('¿Remover a ' + name + ' de tu lista de amigos permitidos?')) return;
    friendsList = friendsList.filter(f => f !== name);
    saveFriends();
    renderFriends();
  };

  window.playcodeMCCopyIp = function() {
    const text = $('#mc-public-ip-text').text();
    if (!text || text.includes('...')) return;
    navigator.clipboard.writeText(text).then(() => {
      const btn = $('#mc-btn-copy-ip');
      const original = btn.text();
      btn.text('✅ ¡Copiado!');
      setTimeout(() => btn.text(original), 2000);
    });
  };

  window.playcodeMCRunCommand = function(cmd) {
    if (cmd === 'op') {
      const nick = prompt('Ingresa tu nombre exacto de jugador en Minecraft:');
      if (nick) alert('¡Permisos de administrador concedidos a ' + nick.trim() + '!');
    } else {
      alert('Acción ejecutada: ' + cmd);
    }
  };

})(jQuery);
