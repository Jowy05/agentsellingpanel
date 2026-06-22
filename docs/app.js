/* Panel de minutos · Agente IA — frontend (vanilla JS, sin build) */
(function () {
  'use strict';
  var app = document.getElementById('app');

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  var S = { user:null, clients:[], periodo:'', view:'stats', mdSel:null, mdFilter:'todos' };
  function initials(n){ var p=(n||'').trim().split(/\s+/); return (((p[0]||'?')[0]||'?')+(p[1]?p[1][0]:'')).toUpperCase(); }
  function isDark(){ return document.documentElement.getAttribute('data-theme')==='dark'; }
  function applyTheme(t){ if(t==='dark') document.documentElement.setAttribute('data-theme','dark'); else document.documentElement.removeAttribute('data-theme'); try{localStorage.setItem('cx_theme',t);}catch(e){} }
  function toggleTheme(){ applyTheme(isDark()?'light':'dark'); var b=document.getElementById('theme'); if(b) b.textContent=isDark()?'☀':'☾'; }
  try{ applyTheme(localStorage.getItem('cx_theme')||'light'); }catch(e){}
  function stk(p){ return p>=100?'cortado':p>=75?'aviso':'ok'; }
  function stCol(p){ return 'var(--c-'+(p>=100?'danger':p>=75?'warn':'ok')+')'; }
  var ACK_KEY = 'cx_alerts_ack';
  var DOC_TITLE = 'Panel Agentes Voz IA — Conexia';
  var BELL_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 0 0 4 0"/></svg>';
  var titleTimer = null;

  async function api(path, body){ return apiCall(path, body, true); }
  async function apiCall(path, body, allowRetry){
    var opt = { method: body ? 'POST':'GET', credentials:'same-origin', headers:{} };
    if (body){ opt.headers['Content-Type']='application/json'; if (S.csrf) opt.headers['X-CSRF-Token']=S.csrf; opt.body=JSON.stringify(body); }
    var res, data;
    try { res = await fetch('api/'+path, opt); } catch(e){ return {status:0, data:{error:'sin conexión con el servidor'}}; }
    try { data = await res.json(); } catch(e){ data = {error:'respuesta no válida'}; }
    if (data && data.csrf) S.csrf = data.csrf;   // el servidor entrega/renueva el token (p.ej. en session.php)
    // Reintento único si el token CSRF caducó (tras logout/regeneración de sesión).
    if (res && res.status===403 && data && data.error==='csrf' && allowRetry && body){
      try { var s=await fetch('api/session.php',{credentials:'same-origin'}); var sd=await s.json(); if (sd && sd.csrf) S.csrf=sd.csrf; } catch(e){}
      return apiCall(path, body, false);
    }
    return { status: res.status, data: data || {} };
  }
  function toast(msg){ var t=document.createElement('div'); t.className='toast'; t.textContent=msg; document.body.appendChild(t); setTimeout(function(){t.remove();},2800); }

  var ERR = {
    'credenciales incorrectas':'Email o contraseña incorrectos.',
    'locked':'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.',
    'no_auth':'Sesión caducada. Vuelve a entrar.',
    '2fa_required':'Falta verificar el doble factor.',
    'Código incorrecto':'Código incorrecto. Revisa la app de autenticación.',
    'faltan_datos':'Faltan datos obligatorios.',
    'ya_inicializado':'El sistema ya tiene un administrador.'
  };
  function emsg(d){ if(!d) return 'Error'; return ERR[d.error] || d.error || 'Error'; }
  function showErr(id,msg){ var e=document.getElementById(id); if(e) e.innerHTML='<div class="auth-error">'+esc(msg)+'</div>'; }
  function estadoBadge(pct){
    var k = pct>=100?'cortado':(pct>=75?'aviso':'ok'), lbl = pct>=100?'Cortado':(pct>=75?'Aviso':'OK');
    return '<span class="state-badge '+k+'"><span class="sd"></span>'+lbl+'</span>';
  }

  /* ============ BOOT ============ */
  async function boot(){
    var r = await api('session.php');
    if (r.status===200 && r.data.user){ S.user=r.data.user; await loadPanel(); }
    else renderLogin();
  }

  /* ============ LOGIN ============ */
  function authShell(inner){ stopTitleBlink(); app.innerHTML = '<div class="auth-wrap"><div class="card auth-card" style="padding:30px 28px"><div class="auth-logo"></div>'+inner+'</div></div>'; }

  function renderLogin(){
    authShell(
      '<h1 class="auth-title">Panel Agentes Voz IA</h1>'+
      '<p class="auth-sub">Acceso del equipo técnico de Conexia</p>'+
      '<form id="f-login">'+
        '<div class="field"><label>Email</label><input class="field-input" type="email" name="email" autocomplete="username" required></div>'+
        '<div class="field"><label>Contraseña</label><input class="field-input" type="password" name="pass" autocomplete="current-password" required></div>'+
        '<div class="auth-actions"><button class="btn btn-primary" type="submit">Entrar</button></div>'+
      '</form><div id="login-err"></div>'
    );
    document.getElementById('f-login').addEventListener('submit', async function(e){
      e.preventDefault();
      var fd=new FormData(e.target), btn=e.target.querySelector('button'); btn.disabled=true;
      var r=await api('login.php',{ email:fd.get('email'), pass:fd.get('pass') });
      btn.disabled=false;
      if (r.status===200 && r.data.step==='2fa') renderVerify();
      else if (r.status===200 && r.data.step==='enroll') renderEnroll();
      else showErr('login-err', emsg(r.data));
    });
  }

  function renderVerify(){
    authShell(
      '<h1 class="auth-title">Verificación en dos pasos</h1>'+
      '<p class="auth-sub">Introduce el código de 6 dígitos de tu app de autenticación (o un código de recuperación).</p>'+
      '<form id="f-verify">'+
        '<div class="field"><label>Código</label><input class="field-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required style="text-align:center;font-family:var(--font-mono);font-size:20px;letter-spacing:.3em"></div>'+
        '<div class="auth-actions"><button class="btn btn-primary" type="submit">Verificar</button></div>'+
      '</form><button class="auth-link" id="back">← Volver</button><div id="v-err"></div>'
    );
    document.getElementById('back').addEventListener('click', renderLogin);
    document.getElementById('f-verify').addEventListener('submit', async function(e){
      e.preventDefault();
      var code=new FormData(e.target).get('code'), btn=e.target.querySelector('button'); btn.disabled=true;
      var r=await api('verify_2fa.php',{code:code}); btn.disabled=false;
      if (r.status===200 && r.data.ok) await loadPanel();
      else showErr('v-err', emsg(r.data));
    });
  }

  async function renderEnroll(){
    authShell(
      '<h1 class="auth-title">Configura el doble factor</h1>'+
      '<p class="auth-sub">Escanea el código con <b>Google Authenticator</b> (o Authy) y escribe el código de 6 dígitos.</p>'+
      '<div class="qr-box" id="qr"></div>'+
      '<div class="muted" style="text-align:center;margin-bottom:6px">¿No puedes escanear? Introduce esta clave a mano:</div>'+
      '<div class="secret-mono" id="secret">…</div>'+
      '<form id="f-enroll" style="margin-top:14px">'+
        '<div class="field"><label>Código de 6 dígitos</label><input class="field-input" name="code" inputmode="numeric" maxlength="9" required style="text-align:center;font-family:var(--font-mono);font-size:20px;letter-spacing:.3em"></div>'+
        '<div class="auth-actions"><button class="btn btn-primary" type="submit">Activar 2FA</button></div>'+
      '</form><button class="auth-link" id="back">← Volver</button><div id="e-err"></div>'
    );
    document.getElementById('back').addEventListener('click', renderLogin);
    var r=await api('setup_2fa.php');
    if (r.status!==200 || !r.data.secret){ showErr('e-err', emsg(r.data)); return; }
    document.getElementById('secret').textContent=r.data.secret;
    try { new QRCode(document.getElementById('qr'), { text:r.data.otpauth_uri, width:184, height:184, correctLevel:QRCode.CorrectLevel.M }); }
    catch(e){ document.getElementById('qr').innerHTML='<span class="muted">Usa la clave de abajo</span>'; }
    document.getElementById('f-enroll').addEventListener('submit', async function(e){
      e.preventDefault();
      var code=new FormData(e.target).get('code'), btn=e.target.querySelector('button'); btn.disabled=true;
      var rr=await api('setup_2fa.php',{code:code}); btn.disabled=false;
      if (rr.status===200 && rr.data.step==='ok') renderRecovery(rr.data.recovery_codes||[]);
      else showErr('e-err', emsg(rr.data));
    });
  }

  function renderRecovery(codes){
    authShell(
      '<h1 class="auth-title">Códigos de recuperación</h1>'+
      '<p class="auth-sub">Guárdalos en un sitio seguro: te permiten entrar si pierdes el móvil. <b>No se volverán a mostrar.</b></p>'+
      '<div class="recovery-grid">'+codes.map(function(c){return '<div class="recovery-code">'+esc(c)+'</div>';}).join('')+'</div>'+
      '<div class="auth-actions"><button class="btn btn-primary" id="rec-ok">He guardado los códigos · Entrar</button></div>'
    );
    document.getElementById('rec-ok').addEventListener('click', loadPanel);
  }

  /* ============ PANEL ============ */
  async function loadPanel(){
    var s=await api('session.php'); if (s.status===200 && s.data.user) S.user=s.data.user; else { renderLogin(); return; }
    await reloadClients(); renderPanel();
  }
  async function reloadClients(){
    var r=await api('clients.php',{action:'list'});
    if (r.status===401){ renderLogin(); return false; }
    if (r.status===200 && r.data.clientes){ S.clients=r.data.clientes; S.periodo=r.data.periodo||''; }
    return true;
  }
  function isAdmin(){ return S.user && S.user.rol==='admin'; }

  /* ---- Auto-refresh: pantalla ~2s, medición RÁPIDA (solo hoy) ~3s, repaso completo del mes ~2min ---- */
  var AUTO={display:null, quick:null, full:null, metering:false}, lastSig='';
  function modalOpen(){ return !!document.querySelector('.modal-scrim'); }
  function refreshableView(){ return S.view==='stats'||S.view==='clientes'; }
  function clientsSig(){ return S.clients.map(function(c){return c.id+':'+c.minutos_usados+':'+c.porcentaje;}).join('|'); }
  async function autoTick(){
    if(document.hidden) return;
    var ok=await reloadClients(); if(ok===false){ stopAuto(); return; }
    updateAlerts();
    var sig=clientsSig();
    if(sig!==lastSig){ lastSig=sig; if(!modalOpen() && refreshableView()) renderView(true); }   // true = sin animación (no parpadea)
  }
  async function autoMeter(scope){
    if(document.hidden || AUTO.metering || !isAdmin()) return;   // solo admin; sin solapar (rápida vs completa comparten cerrojo)
    AUTO.metering=true;
    try{
      var r=await api('metering.php', scope==='today'?{scope:'today'}:{});
      if(r.status===401){ stopAuto(); renderLogin(); return; }
      var ok=await reloadClients(); if(ok===false){ stopAuto(); return; }
      updateAlerts();
      var sig=clientsSig();
      if(sig!==lastSig){ lastSig=sig; if(!modalOpen() && refreshableView()) renderView(true); }   // solo redibuja si cambió algo
    } finally { AUTO.metering=false; }
  }
  function startAuto(){
    stopAuto(); lastSig=clientsSig();
    AUTO.display=setInterval(autoTick, 2000);
    AUTO.quick=setInterval(function(){ autoMeter('today'); }, 3000);    // medición continua del día (1 consulta/agente)
    AUTO.full=setInterval(function(){ autoMeter('month'); }, 120000);   // repaso completo del mes de fondo
  }
  function stopAuto(){ ['display','quick','full'].forEach(function(k){ if(AUTO[k]){ clearInterval(AUTO[k]); AUTO[k]=null; } }); }

  function renderPanel(){
    var views=[['clientes','Clientes'],['stats','Stats'],['mail','Avisos']];
    if (isAdmin()) views.push(['equipo','Equipo']);
    app.innerHTML =
      '<header class="appbar"><div class="appbar-inner">'+
        '<div class="brand"><div class="logo"></div><div class="divider"></div>'+
          '<div class="titles"><h1>Panel Agentes Voz IA</h1></div></div>'+
        '<div class="appbar-right">'+
          '<span class="userchip" id="userchip" title="Cambiar contraseña"><span class="uc-meta"><span class="uc-name">'+esc(S.user.nombre)+'</span><span class="uc-rol">'+esc(S.user.rol)+'</span></span><span class="avatar">'+esc(initials(S.user.nombre))+'</span></span>'+
          '<button class="icon-pill" id="key" title="Cambiar contraseña">🔑</button>'+
          '<div class="notif-wrap"><button class="notif-btn" id="notif" title="Avisos" aria-label="Avisos">'+BELL_SVG+'<span class="notif-badge" id="notif-badge"></span></button></div>'+
          '<button class="theme-toggle" id="theme" title="Tema claro / oscuro">'+(isDark()?'☀':'☾')+'</button>'+
          '<button class="icon-pill salir" id="logout" title="Cerrar sesión">⎋ Salir</button>'+
        '</div>'+
      '</div></header>'+
      '<div class="app">'+
      '<div class="nav"><div class="nav-segmented">'+views.map(function(v){return '<button class="seg'+(v[0]===S.view?' active':'')+'" data-v="'+v[0]+'">'+v[1]+'</button>';}).join('')+'</div></div>'+
      '<div id="view"></div></div>'+
      '<button class="help-fab" id="help-fab" title="Guía de uso" aria-label="Guía de uso"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></button>';
    document.getElementById('logout').addEventListener('click', async function(){ stopAuto(); await api('logout.php',{}); renderLogin(); });
    document.getElementById('notif').addEventListener('click', function(e){ e.stopPropagation(); toggleNotif(); });
    document.getElementById('help-fab').addEventListener('click', openGuide);
    document.getElementById('userchip').addEventListener('click', openChangePass);
    document.getElementById('key').addEventListener('click', openChangePass);
    document.getElementById('theme').addEventListener('click', toggleTheme);
    app.querySelectorAll('.seg').forEach(function(b){ b.addEventListener('click', function(){ S.view=b.dataset.v; renderView(); }); });
    renderView();
    startAuto();
  }

  /* ---- Guía de uso (botón flotante) ---- */
  function openGuide(){
    function donutMini(pct, nombre, mins){
      return '<div class="donut-card" style="cursor:default">'+donutSVG(pct)+'<div class="dc-name">'+nombre+'</div><div class="dc-mins">'+mins+'</div></div>';
    }
    var secs = [
      { n:'1', t:'Acceso (login + 2FA)', body:
        '<ol><li>Entra con tu <b>correo</b> y <b>contraseña</b>.</li>'+
        '<li>La <b>primera vez</b>: escanea el <b>QR</b> con Google Authenticator y guarda los códigos de recuperación.</li>'+
        '<li>En cada acceso, mete el <b>código de 6 dígitos</b> de la app (caduca cada 30 s).</li>'+
        '<li>Para <b>cambiar tu contraseña</b>: pulsa el <b>🔑</b> junto a tu nombre (arriba a la derecha).</li></ol>' },
      { n:'2', t:'Clientes (alta y edición)', body:
        '<ol><li>Pestaña <b>Clientes</b> → <b>+ Añadir cliente</b>.</li>'+
        '<li>Pon nombre, <b>Tenant</b> (el <b>código</b>, p.ej. <code>216</code>), <b>minutos contratados</b> (total del cliente) e <b>IVR de corte por defecto</b>.</li>'+
        '<li>Con los iconos: <b>✎</b> editar, <b>🗑</b> eliminar.</li></ol>',
        demo: '<div class="cli-table"><div class="cli-row"><div class="cli-name"><b>Clínica Dental Sonrisa</b><span class="cli-sector">Salud · tenant 216</span></div><div class="cli-plan hide-sm">Plan 500</div><div class="r cli-mins hide-sm">500 min</div><div class="r cli-pct" style="color:var(--c-ok)">36%</div><div class="r cli-actions"><button class="icon-btn" type="button">✎</button><button class="icon-btn danger" type="button">🗑</button></div></div></div>' },
      { n:'3', t:'Ficha y agentes IA', body:
        '<ol><li>Haz <b>clic en el nombre</b> del cliente para abrir su ficha.</li>'+
        '<li>En <b>Agentes IA</b>: <b>Leer agentes</b> detecta solos los que tienen DID; o <b>Agregar agente</b> a mano (nombre, dial number, DDI, IVR de corte).</li>'+
        '<li>Verás los <b>minutos por agente</b> del periodo.</li></ol>',
        demo: '<div class="agent-row"><div class="agent-meta"><div><div class="agent-name">INTELIGENCIA ARTIFICIAL SKYNET</div><div class="agent-sub">DDI 930905100 · corte: 114</div></div></div><div class="agent-end"><span class="agent-minutes">107<span class="unit">min</span></span></div></div>' },
      { n:'4', t:'Consumo y Stats', body:
        '<ol><li>Pestaña <b>Stats</b>: un <b>círculo por cliente</b> con el % usado (verde &lt;75, ámbar 75-99, rojo 100) y «usado / contratado».</li>'+
        '<li><b>Clic en un cliente</b> → abre su ficha.</li>'+
        '<li><b>Actualizar consumo</b> recalcula desde el CDR de la centralita. El panel además se <b>actualiza solo</b>.</li>'+
        '<li>El <b>día 1 de cada mes</b> el consumo vuelve a <b>0</b> automáticamente (los meses anteriores quedan como histórico).</li></ol>',
        demo: '<div class="donut-grid" style="padding:0;grid-template-columns:repeat(2,1fr);gap:12px">'+donutMini(36,'Sonrisa','180 / 500 min')+donutMini(100,'conexia','500 / 500 min')+'</div>' },
      { n:'5', t:'Avisos (campana y correos)', body:
        '<ol><li>La <b>campana</b> de arriba («Avisos») tiene <b>dos secciones</b>: <b>Consumo</b> y <b>Agentes desactivados</b>.</li>'+
        '<li><b>Consumo:</b> al <b>75%</b> y <b>100%</b> el cliente recibe un <b>correo</b> automático (copia a SAC); se manda una vez por umbral y se rearma si baja del 75%. Estos avisos <b>se quitan solos</b> al bajar el consumo.</li>'+
        '<li><b>Agentes desactivados:</b> cada agente cortado aparece aquí y <b>no se quita</b> hasta que un admin pulsa <b>«✅ Reactivado»</b>.</li></ol>',
        demo: '<div class="notif-dropdown" style="position:static;width:auto;box-shadow:none;border-radius:10px"><div class="nd-head">Avisos</div><div class="nd-sub">Consumo</div><div class="notif-item"><span><b>Inmobiliaria Vista</b> · 100%</span><span class="state-badge cortado"><span class="sd"></span>100%</span></div><div class="nd-sub">Agentes desactivados</div><div class="notif-item"><span><b>Agente Vista</b> · Inmobiliaria <span class="cut-tag"><span class="sd"></span>Desviado</span></span></div></div>' },
      { n:'6', t:'Corte automático y reactivación', body:
        '<ol><li>Al <b>100%</b>, el agente se <b>corta solo</b>: su DID se desvía al IVR de corte (deja de atender).</li>'+
        '<li>La <b>reactivación es manual</b> en la centralita: el botón <b>«Cómo reactivar»</b> te da el enlace directo al DID (Destination → AI Voice Agents → el agente).</li>'+
        '<li>Cuando lo hayas hecho, pulsa <b>«✅ Marcar reactivado»</b> para ponerlo activo en el panel.</li>'+
        '<li>El agente cortado también queda en la <b>campana</b> (Avisos → Agentes desactivados) hasta reactivarlo.</li></ol>',
        demo: '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span class="cut-tag"><span class="sd"></span>Cortado</span><button class="btn btn-ghost btn-sm" type="button">Cómo reactivar</button><button class="btn btn-primary btn-sm" type="button">✅ Marcar reactivado</button></div>' },
      { n:'7', t:'Equipo (solo admin)', body:
        '<ol><li>Pestaña <b>Equipo</b>: crea miembros del equipo técnico.</li>'+
        '<li>Cada uno entra con su correo y configura <b>su propio 2FA</b> en el primer acceso.</li></ol>' },
    ];
    var toc  = secs.map(function(s,i){ return '<a data-goto="'+i+'">'+s.n+'. '+esc(s.t)+'</a>'; }).join('');
    var body = secs.map(function(s,i){
      return '<div class="guide-sec" id="gsec-'+i+'"><h3><span class="gs-num">'+s.n+'</span>'+esc(s.t)+'</h3>'+s.body+
        (s.demo ? '<div class="guide-demo"><div class="gd-label">Así se ve</div>'+s.demo+'</div>' : '')+'</div>';
    }).join('');
    var html='<div class="modal-scrim" id="scrimguide"><div class="modal guide-modal"><div class="modal-head"><div>'+
      '<h3 class="mh-name">Guía de uso del panel</h3><div class="mh-meta">Cómo funciona cada parte</div></div>'+
      '<button class="x-close" id="xguide">✕</button></div>'+
      '<div class="modal-body guide-body"><p class="guide-intro">Panel para revender el Agente de Voz IA por minutos. <b>Roles:</b> el <b>administrador</b> da de alta y edita clientes/agentes, corta/reactiva y envía correos; el <b>técnico</b> lo consulta todo y cambia su contraseña (los cambios los realiza un administrador). Estas son las partes y cómo se usan:</p>'+
      '<div class="guide-toc">'+toc+'</div>'+body+'</div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimguide');
    function close(){ scrim.remove(); }
    document.getElementById('xguide').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    scrim.querySelectorAll('[data-goto]').forEach(function(a){ a.addEventListener('click', function(){ var el=document.getElementById('gsec-'+a.dataset.goto); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); }); });
  }

  /* ---- Cambiar contraseña (propia) ---- */
  function openChangePass(){
    var html='<div class="modal-scrim" id="scrimcp"><div class="modal" style="max-width:440px"><div class="modal-head"><div>'+
      '<h3 class="mh-name">Cambiar contraseña</h3><div class="mh-meta">'+esc(S.user.email||S.user.nombre||'')+'</div></div>'+
      '<button class="x-close" id="xcp">✕</button></div><div class="modal-body"><form id="f-cp">'+
      '<div class="field"><label>Contraseña actual</label><input class="field-input" type="password" name="current" autocomplete="current-password" required></div>'+
      '<div class="field"><label>Nueva contraseña (mín. 10)</label><input class="field-input" type="password" name="new" autocomplete="new-password" minlength="10" required></div>'+
      '<div class="field"><label>Repetir nueva</label><input class="field-input" type="password" name="new2" autocomplete="new-password" required></div>'+
      '<div id="cp-err"></div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">'+
      '<button type="button" class="btn btn-ghost" id="cpcancel">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div></form></div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimcp'); function close(){ scrim.remove(); }
    document.getElementById('xcp').addEventListener('click', close);
    document.getElementById('cpcancel').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    document.getElementById('f-cp').addEventListener('submit', async function(e){
      e.preventDefault(); var fd=new FormData(e.target);
      var cur=fd.get('current'), nw=(fd.get('new')||''), nw2=fd.get('new2');
      if(nw!==nw2){ showErr('cp-err','Las contraseñas nuevas no coinciden.'); return; }
      if(nw.length<10){ showErr('cp-err','La nueva debe tener al menos 10 caracteres.'); return; }
      var r=await api('change_pass.php',{current:cur, new:nw});
      if(r.data&&r.data.ok){ close(); toast('Contraseña cambiada ✓'); }
      else showErr('cp-err', emsg(r.data));
    });
  }

  /* ---- Avisos / notificaciones ---- */
  function computeAlerts(){
    var list = S.clients.filter(function(c){ return c.porcentaje >= 75; })
      .map(function(c){ return { nombre:c.nombre, pct:c.porcentaje, level: c.porcentaje>=100?'danger':'warn' }; })
      .sort(function(a,b){ return b.pct - a.pct; });
    // Agentes desviados (cortados): persisten hasta que se marca la reactivación.
    var cuts = [];
    S.clients.forEach(function(c){
      (c.agentes_cortados||[]).forEach(function(a){ cuts.push({ agentId:a.id, agente:a.nombre||'Agente', cliente:c.nombre, clienteId:c.id, ddi:a.ddi||'' }); });
    });
    var level = (list.some(function(x){ return x.level==='danger'; }) || cuts.length) ? 'danger' : (list.length ? 'warn' : null);
    var parts = list.map(function(x){ return x.nombre+':'+x.level; }).concat(cuts.map(function(x){ return 'cut'+x.agentId; }));
    return { list:list, cuts:cuts, level:level, sig:parts.join('|') };
  }
  function alertsAcked(sig){ try { return localStorage.getItem(ACK_KEY) === sig; } catch(e){ return false; } }
  function ackAlerts(sig){ try { localStorage.setItem(ACK_KEY, sig); } catch(e){} }

  function stopTitleBlink(){ if (titleTimer){ clearInterval(titleTimer); titleTimer = null; } document.title = DOC_TITLE; }

  function updateAlerts(){
    var a = computeAlerts();
    var total = a.list.length + a.cuts.length;
    var unacked = a.sig !== '' && !alertsAcked(a.sig);
    var btn = document.getElementById('notif');
    if (btn){ btn.classList.remove('has-alert','warn','danger'); if (total) btn.classList.add('has-alert', a.level); }
    var badge = document.getElementById('notif-badge');
    if (badge){ badge.textContent = total ? String(total) : ''; badge.className = 'notif-badge' + (total ? (' show' + (a.level==='warn' ? ' warn' : '')) : ''); }
    // Parpadeo en el TÍTULO DE LA PESTAÑA: el "(N)" aparece y desaparece.
    if (titleTimer){ clearInterval(titleTimer); titleTimer = null; }
    if (unacked && total){
      var pre = '(' + total + ') ';
      var on = true; document.title = pre + DOC_TITLE;
      titleTimer = setInterval(function(){ on = !on; document.title = (on ? pre : '') + DOC_TITLE; }, 900);
    } else {
      document.title = DOC_TITLE;
    }
    return a;
  }

  function toggleNotif(){
    var wrap = document.querySelector('.notif-wrap'); if (!wrap) return;
    var open = wrap.querySelector('.notif-dropdown');
    if (open){ open.remove(); return; }
    var a = computeAlerts();
    // Sección 1: consumo (75% / 100%). Se autorresuelven al bajar el consumo.
    var consumo = a.list.length ? a.list.map(function(x){
      var badge = x.level==='danger'
        ? '<span class="state-badge cortado"><span class="sd"></span>100%</span>'
        : '<span class="state-badge aviso"><span class="sd"></span>Aviso</span>';
      return '<div class="notif-item"><span><b>'+esc(x.nombre)+'</b> · '+x.pct+'%</span>'+badge+'</div>';
    }).join('') : '<div class="notif-empty">Sin avisos de consumo.</div>';
    // Sección 2: agentes desviados. PERSISTEN hasta marcar reactivado (no se quitan solos).
    var admin = isAdmin();
    var cortes = a.cuts.length ? a.cuts.map(function(x){
      var btns = admin
        ? '<div style="display:flex;gap:6px;flex-wrap:wrap;width:100%;justify-content:flex-end;margin-top:6px">'
          + '<button class="btn btn-ghost btn-sm" data-guide-bell="'+x.agentId+'" title="Genera el enlace directo al DID en la centralita">🔗 Cómo reactivar</button>'
          + '<button class="btn btn-primary btn-sm" data-react-bell="'+x.agentId+'" data-nom="'+esc(x.agente)+'" title="Ya lo he reactivado en la centralita">✅ Reactivado</button></div>'
        : '';
      return '<div class="notif-item"><span><b>'+esc(x.agente)+'</b> · '+esc(x.cliente)+' <span class="cut-tag"><span class="sd"></span>Desviado</span></span>'+btns+'</div>';
    }).join('') : '<div class="notif-empty">Ningún agente desviado.</div>';
    var dd = document.createElement('div'); dd.className = 'notif-dropdown';
    dd.innerHTML = '<div class="nd-head">Avisos</div>'
      + '<div class="nd-sub">Consumo</div>' + consumo
      + '<div class="nd-sub">Agentes desactivados</div>' + cortes;
    wrap.appendChild(dd);
    dd.querySelectorAll('[data-react-bell]').forEach(function(b){
      b.addEventListener('click', function(e){ e.stopPropagation(); doReactivadoFromBell(+b.dataset.reactBell, b.dataset.nom); });
    });
    dd.querySelectorAll('[data-guide-bell]').forEach(function(b){
      b.addEventListener('click', function(e){ e.stopPropagation();
        var x=a.cuts.filter(function(z){ return String(z.agentId)===b.dataset.guideBell; })[0]; if(!x) return;
        openCorteGuide({ id:x.agentId, nombre:x.agente, ddi:x.ddi }, findClient(x.clienteId)||{ id:x.clienteId, desvio_100:'' });
      });
    });
    ackAlerts(a.sig);   // abrir el panel = marcar como leído -> deja de parpadear (los desviados siguen listados)
    updateAlerts();
    setTimeout(function(){ document.addEventListener('click', function onDoc(e){ if (!wrap.contains(e.target)){ dd.remove(); document.removeEventListener('click', onDoc); } }); }, 0);
  }
  // Reactivar un agente desde la campana (admin). Marca estado='normal' en BD; la reactivación
  // real en la centralita la ha hecho el técnico a mano (la API no devuelve el DID al agente IA).
  async function doReactivadoFromBell(agentId, nom){
    if(!confirm('¿Ya has reactivado «'+(nom||'el agente')+'» en la centralita (Destination → AI Voice Agents)?\n\nPulsa Aceptar SOLO si ya lo has hecho en la GUI; esto lo quita de los avisos.')) return;
    var r=await api('divert.php',{agent_id:agentId, action:'reactivado'});
    if(r.data&&r.data.ok){
      toast('Marcado como reactivado');
      await reloadClients();
      var dd=document.querySelector('.notif-dropdown'); if(dd) dd.remove();
      toggleNotif();   // reabrir con la lista ya actualizada
    } else toast(emsg(r.data));
  }

  function renderView(quiet){
    app.querySelectorAll('.seg').forEach(function(b){ b.classList.toggle('active', b.dataset.v===S.view); });
    var v=document.getElementById('view');
    if (S.view==='clientes'){ v.innerHTML=viewClientes(); bindClientes(); }
    else if (S.view==='stats'){ v.innerHTML=viewStats(); bindStats(); bindDatos(); }
    else if (S.view==='mail'){ v.innerHTML=viewMail(); bindMail(); }
    else if (S.view==='equipo'){ viewEquipo(v); }
    if (quiet) v.querySelectorAll('.view-enter').forEach(function(e){ e.classList.remove('view-enter'); });  // refresco automático: sin animación de entrada
    updateAlerts();
  }

  /* ---- Clientes (CRUD) ---- */
  function viewClientes(){
    var admin = isAdmin();
    var rows = S.clients.map(function(c){
      return '<div class="cli-row">'+
        '<div class="cli-name"><b data-ficha="'+c.id+'">'+esc(c.nombre)+'</b><span class="cli-sector">'+esc(c.sector||'—')+(c.ddi?' · DDI '+esc(c.ddi):'')+(c.tenant?' · tenant '+esc(c.tenant):'')+'</span></div>'+
        '<div class="cli-plan hide-sm">'+esc(c.plan||'—')+'</div>'+
        '<div class="r cli-mins hide-sm">'+c.minutos_contratados+' min</div>'+
        '<div class="r cli-pct" style="color:'+(c.porcentaje>=100?'var(--c-danger)':c.porcentaje>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.porcentaje+'%</div>'+
        '<div class="r cli-actions">'+(admin
          ? '<button class="icon-btn" data-edit="'+c.id+'" title="Editar">✎</button><button class="icon-btn danger" data-del="'+c.id+'" title="Eliminar">🗑</button>'
          : '')+'</div></div>';
    }).join('');
    return '<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Clientes</h2>'+
      '<p class="ph-sub">'+(admin?'Alta, edición y baja · datos guardados en el servidor':'Solo lectura · contacta con un administrador para cambios')+'</p></div>'+
      (admin?'<button class="btn btn-primary" id="add-cli">+ Añadir cliente</button>':'')+'</div>'+
      '<div class="cli-table"><div class="cli-head"><span>Cliente</span><span class="hide-sm">Plan</span><span class="r hide-sm">Contratados</span><span class="r">Uso</span><span class="r">Acciones</span></div>'+
      (S.clients.length?rows:('<div class="cli-empty">No hay clientes'+(admin?'. Pulsa «Añadir cliente».':'.')+'</div>'))+'</div></div>';
  }
  function bindClientes(){
    var addBtn=document.getElementById('add-cli'); if(addBtn) addBtn.addEventListener('click', function(){ openClientForm(null); });
    app.querySelectorAll('[data-edit]').forEach(function(b){ b.addEventListener('click', function(){ openClientForm(findClient(+b.dataset.edit)); }); });
    app.querySelectorAll('[data-del]').forEach(function(b){ b.addEventListener('click', async function(){
      var c=findClient(+b.dataset.del); if(!c) return;
      if(!confirm('¿Eliminar el cliente «'+c.nombre+'»?')) return;
      var r=await api('clients.php',{action:'delete', id:c.id});
      if(r.status===200 && r.data.ok){ toast('Cliente eliminado'); await reloadClients(); renderView(); } else toast(emsg(r.data));
    });});
    app.querySelectorAll('[data-ficha]').forEach(function(b){ b.addEventListener('click', function(){ openFicha(findClient(+b.dataset.ficha)); }); });
  }
  function findClient(id){ return S.clients.filter(function(c){return c.id===id;})[0]; }

  function openClientForm(c){
    var f = c || { nombre:'',correo:'',sector:'',plan:'',minutos_contratados:0,alta:'',tenant:'',ddi:'',desvio_100:'' };
    function fld(label,name,type,ph,span){ return '<div class="field'+(span?' span-2':'')+'"><label>'+label+'</label><input class="field-input" name="'+name+'" '+(type==='number'?'type="number" min="0"':'')+' value="'+esc(f[name])+'" placeholder="'+esc(ph||'')+'"></div>'; }
    var html='<div class="modal-scrim" id="scrim"><div class="modal"><div class="modal-head"><div>'+
      '<h3 class="mh-name">'+(c?'Editar cliente':'Nuevo cliente')+'</h3><div class="mh-meta">Se guarda en el servidor</div></div>'+
      '<button class="x-close" id="x">✕</button></div><div class="modal-body"><form id="f-cli"><div class="form-grid">'+
      fld('Nombre','nombre','text','Ej. Clínica Dental Sonrisa',true)+
      fld('Correo','correo','text','contacto@cliente.com')+
      fld('Sector','sector','text','Ej. Salud')+
      fld('Plan','plan','text','Ej. Plan Recepción 500')+
      fld('Minutos contratados (total)','minutos_contratados','number','')+
      fld('Alta','alta','text','Ej. Jun 2026')+
      fld('Tenant (código, p.ej. 216)','tenant','text','Código de tenant en la centralita')+
      fld('IVR de corte por defecto','desvio_100','text','Se usa si un agente no tiene el suyo',true)+
      '</div><div id="cli-err"></div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">'+
      '<button type="button" class="btn btn-ghost" id="cancel">Cancelar</button>'+
      '<button type="submit" class="btn btn-primary">Guardar</button></div></form></div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrim');
    function close(){ scrim.remove(); }
    document.getElementById('x').addEventListener('click', close);
    document.getElementById('cancel').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    document.getElementById('f-cli').addEventListener('submit', async function(e){
      e.preventDefault();
      var fd=new FormData(e.target), o={action: c?'update':'create'};
      if(c) o.id=c.id;
      ['nombre','correo','sector','plan','alta','tenant','desvio_100'].forEach(function(k){ o[k]=(fd.get(k)||'').trim(); });
      o.minutos_contratados = parseInt(fd.get('minutos_contratados')||'0',10)||0;
      if(!o.nombre){ showErr('cli-err','El nombre es obligatorio.'); return; }
      var r=await api('clients.php', o);
      if(r.data && (r.data.ok || r.data.id)){ close(); toast('Cliente guardado'); await reloadClients(); renderView(); if(c) maybePromptReactivar(c.id); }
      else showErr('cli-err', emsg(r.data));
    });
  }
  // Si tras ampliar minutos el cliente baja del 100% y tiene agentes cortados, abre el popup de reactivación con el enlace.
  async function maybePromptReactivar(clientId){
    var cli=findClient(clientId); if(!cli || cli.porcentaje>=100) return;
    var r=await api('agents.php',{action:'list',client_id:clientId});
    var ags=((r.data&&r.data.agentes)||[]).filter(function(a){ return a.estado_desvio==='cortado' && a.ddi; });
    if(ags.length) openCorteGuide(ags[0], cli);
  }

  function openFicha(c){
    if(!c) return;
    var pct=c.porcentaje, restantes=c.minutos_contratados - c.minutos_usados;
    var agentsSection = '<div class="modal-agents" id="ficha-agents"><div class="ma-title">Agentes IA</div><div class="muted" style="padding:6px 0">Cargando…</div></div>';
    var html='<div class="modal-scrim" id="scrimf"><div class="modal"><div class="modal-head"><div>'+
      '<h3 class="mh-name">'+esc(c.nombre)+'</h3><div class="mh-meta">'+esc(c.sector||'—')+' · '+esc(c.plan||'—')+'</div></div>'+
      '<button class="x-close" id="xf">✕</button></div><div class="modal-body">'+
      '<div class="total-foot" style="margin:0"><div class="tf-left"><div class="tf-label">Usado / contratados</div>'+
      '<div class="tf-fig"><span class="tf-used" style="color:'+(pct>=100?'var(--c-danger)':pct>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.minutos_usados+'</span><span class="tf-of">/ '+c.minutos_contratados+' min</span></div></div>'+
      '<div class="tf-right"><div class="tf-pct" style="font-size:38px;color:'+(pct>=100?'var(--c-danger)':pct>=75?'var(--c-warn)':'var(--c-ok)')+'">'+pct+'%</div>'+estadoBadge(pct)+'</div></div>'+
      '<div class="kv-grid"><div class="kv"><div class="k">Minutos restantes</div><div class="v">'+restantes+' min</div></div>'+
      '<div class="kv"><div class="k">Correo</div><div class="v" style="font-size:13px;font-family:var(--font-mono)">'+esc(c.correo||'—')+'</div></div>'+
      '<div class="kv"><div class="k">Tenant</div><div class="v" style="font-family:var(--font-mono)">'+esc(c.tenant||'—')+'</div></div>'+
      '<div class="kv"><div class="k">IVR corte (def.)</div><div class="v" style="font-size:13px">'+esc(c.desvio_100||'—')+'</div></div></div>'+
      agentsSection+'</div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimf');
    function close(){ scrim.remove(); }
    document.getElementById('xf').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    loadFichaAgents(c);
  }

  async function loadFichaAgents(c){
    var box=document.getElementById('ficha-agents'); if(!box) return;
    var admin=isAdmin();
    var r=await api('agents.php',{action:'list',client_id:c.id});
    var ags=(r.data&&r.data.agentes)||[];
    var rows = ags.length ? ags.map(function(a){
      var cut = a.estado_desvio==='cortado';
      var divBtn='';
      if(admin && a.ddi){
        divBtn = cut
          ? '<button class="btn btn-ghost btn-sm" data-guide="'+a.id+'" title="Cómo reactivar en la centralita">Cómo reactivar</button><button class="btn btn-primary btn-sm" data-react="'+a.id+'" title="Lo he reactivado en la centralita">✅ Marcar reactivado</button>'
          : '<button class="btn btn-ghost btn-sm" data-cut="'+a.id+'" title="Cortar ahora (desviar al IVR de corte)">Cortar ahora</button>';
      }
      return '<div class="agent-row"><div class="agent-meta"><div>'+
        '<div class="agent-name">'+esc(a.nombre)+(cut?' <span class="cut-tag"><span class="sd"></span>Cortado</span>':'')+'</div>'+
        '<div class="agent-sub">DDI '+esc(a.ddi||'—')+(a.dial_number?' · dial '+esc(a.dial_number):'')+' · corte: '+esc(a.ivr_corte||'(por defecto)')+'</div></div></div>'+
        '<div class="agent-end"><span class="agent-minutes">'+(a.minutos||0)+'<span class="unit">min</span></span>'+divBtn+
        (admin?'<button class="icon-btn danger" data-delag="'+a.id+'" title="Quitar agente">🗑</button>':'')+'</div></div>';
    }).join('') : '<div class="muted" style="padding:6px 0">Sin agentes'+(admin?'. Pulsa «Leer agentes» o «Agregar agente».':'.')+'</div>';
    box.innerHTML = '<div class="ma-title">Agentes IA · consumo del periodo</div>'+rows+
      (admin
        ? '<div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">'+
          '<button class="btn btn-ghost btn-sm" id="ag-meter">↻ Actualizar consumo</button>'+
          '<button class="btn btn-ghost btn-sm" id="ag-read">Leer agentes</button>'+
          '<button class="btn btn-primary btn-sm" id="ag-add">+ Agregar agente</button></div>'+
          '<div class="muted" style="margin-top:8px">«Leer agentes» trae los de DID público; el resto se añaden a mano con su dial number. «Actualizar consumo» recalcula los minutos desde el CDR (puede tardar unos segundos).</div>'
        : '');
    var addB=document.getElementById('ag-add'); if(addB) addB.addEventListener('click', function(){ openAgentForm(c.id); });
    var readB=document.getElementById('ag-read'); if(readB) readB.addEventListener('click', function(){ readAgents(c.id); });
    var meterBtn=document.getElementById('ag-meter'); if(meterBtn) meterBtn.addEventListener('click', function(){ refreshConsumo(c.id); });
    box.querySelectorAll('[data-guide]').forEach(function(b){ b.addEventListener('click', function(){
      var ag=ags.filter(function(x){ return String(x.id)===b.dataset.guide; })[0]; if(ag) openCorteGuide(ag, c);
    });});
    box.querySelectorAll('[data-cut]').forEach(function(b){ b.addEventListener('click', function(){ doCut(+b.dataset.cut, c); }); });
    box.querySelectorAll('[data-react]').forEach(function(b){ b.addEventListener('click', function(){
      var ag=ags.filter(function(x){ return String(x.id)===b.dataset.react; })[0]; doReactivado(+b.dataset.react, c, ag);
    });});
    box.querySelectorAll('[data-delag]').forEach(function(b){ b.addEventListener('click', async function(){
      if(!confirm('¿Quitar este agente?')) return;
      var rr=await api('agents.php',{action:'delete',id:+b.dataset.delag});
      if(rr.data&&rr.data.ok){ toast('Agente quitado'); loadFichaAgents(c); } else toast(emsg(rr.data));
    });});
  }
  async function doCut(agentId, c){
    if(!confirm('Esto desviará el agente al IVR de corte en la CENTRALITA REAL. ¿Continuar?')) return;
    var r=await api('divert.php',{agent_id:agentId, action:'cut'});
    if(r.data&&r.data.ok){ toast('Agente cortado (desviado al IVR '+(r.data.destino||'')+')'); loadFichaAgents(c); }
    else toast('PBX: '+emsg(r.data));
  }
  async function doReactivado(agentId, c, ag){
    var nom = (ag&&ag.nombre)?ag.nombre:'el agente';
    if(!confirm('¿Ya has reactivado «'+nom+'» en la centralita (Destination → AI Voice Agents)?\n\nPulsa Aceptar SOLO si ya lo has hecho en la GUI; esto lo marca como activo en el panel.\nSi aún no, pulsa Cancelar y te abro la guía.')){ if(ag) openCorteGuide(ag,c); return; }
    var r=await api('divert.php',{agent_id:agentId, action:'reactivado'});
    if(r.data&&r.data.ok){ toast('Marcado como reactivado'); loadFichaAgents(c); }
    else toast(emsg(r.data));
  }
  // Guía: el corte es AUTOMÁTICO al 100%; la REACTIVACIÓN se hace a mano en la GUI (la API no puede devolver el DID al agente IA).
  function openCorteGuide(a, c){
    var corte = a.ivr_corte || c.desvio_100 || '(configura un IVR de corte)';
    var did = esc(a.ddi||'—');
    var html='<div class="modal-scrim" id="scrimg"><div class="modal" style="max-width:560px"><div class="modal-head"><div>'+
      '<h3 class="mh-name">Reactivar el agente en la centralita</h3>'+
      '<div class="mh-meta">'+esc(a.nombre)+' · DID '+did+'</div></div>'+
      '<button class="x-close" id="xg">✕</button></div><div class="modal-body">'+
      '<p class="muted" style="margin:0 0 14px">El <b>corte al 100% es automático</b> (el panel desvía el DID al IVR de corte <code>'+esc(corte)+'</code>). La <b>reactivación es manual</b>: cuando el cliente amplíe minutos, devuélvelo al agente — 3 clics:</p>'+
      '<a id="gui-link" class="btn btn-primary" style="display:block;text-align:center;margin:0 0 16px;pointer-events:none;opacity:.6" target="_blank" rel="noopener">🔗 Generando enlace al DID…</a>'+
      '<div style="margin:0 0 8px"><div style="font-weight:700;margin-bottom:6px;color:var(--c-ok)">Reactivar</div>'+
      '<ol style="margin:0;padding-left:20px;line-height:1.9">'+
        '<li>Pulsa el botón de arriba (te abre el DID <code>'+did+'</code> en PBXware) o ve a <b>DIDs</b> y ábrelo.</li>'+
        '<li>En <b>Destination</b> elige <b>«AI Voice Agents»</b> → Value = <code>'+esc(a.nombre)+'</code>.</li>'+
        '<li>Pulsa <b>Guardar</b>.</li>'+
        '<li>Vuelve al panel y pulsa <b>«✅ Marcar reactivado»</b>.</li></ol></div>'+
      '<p class="muted" style="margin:14px 0 0">El corte también puedes forzarlo a mano con <b>«Cortar ahora»</b> (desvía al IVR <code>'+esc(corte)+'</code>).</p>'+
      '</div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimg');
    function close(){ scrim.remove(); }
    document.getElementById('xg').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    // Resuelve el enlace directo a la pantalla de edición del DID.
    api('agents.php',{action:'gui_url',id:a.id}).then(function(r){
      var link=document.getElementById('gui-link'); if(!link) return;
      if(r.data&&r.data.ok&&r.data.url){
        link.href=r.data.url; link.style.pointerEvents='auto'; link.style.opacity='1';
        link.textContent='🔗 Abrir el DID '+(a.ddi||'')+' en la centralita';
      } else {
        link.style.opacity='1'; link.classList.remove('btn-primary'); link.classList.add('btn-ghost');
        link.textContent='⚠ No se pudo generar el enlace (ábrelo a mano: DIDs)';
      }
    });
  }
  async function refreshConsumo(clientId){
    toast('Actualizando consumo desde el CDR… puede tardar unos segundos');
    var r=await api('metering.php',{client_id:clientId});
    if(!(r.data&&r.data.ok)){ toast(emsg(r.data)); return; }
    await reloadClients();
    var scrim=document.getElementById('scrimf'); if(scrim) scrim.remove();
    var c=findClient(clientId); if(c) openFicha(c);
    toast('Consumo actualizado');
  }
  async function readAgents(clientId){
    toast('Leyendo agentes de la centralita…');
    var r=await api('agents.php',{action:'read',client_id:clientId});
    if(!(r.data&&r.data.agentes)){ toast(emsg(r.data)); return; }
    if(!r.data.agentes.length){ toast('La centralita no devolvió agentes con DID directo. Añádelos a mano.'); return; }
    var nuevos=r.data.agentes.filter(function(a){return !a.ya_dado_alta;});
    if(!nuevos.length){ toast('Los agentes detectados ya están dados de alta.'); return; }
    var imp=await api('agents.php',{action:'import',client_id:clientId,agentes:nuevos});
    toast('Importados '+((imp.data&&imp.data.importados)||0)+' agente(s)');
    var c=findClient(clientId); loadFichaAgents(c||{id:clientId});
  }
  function openAgentForm(clientId){
    var html='<div class="modal-scrim" id="scrimag"><div class="modal" style="max-width:460px"><div class="modal-head"><div><h3 class="mh-name">Agregar agente</h3><div class="mh-meta">Datos del agente de voz</div></div><button class="x-close" id="xag">✕</button></div><div class="modal-body"><form id="f-ag">'+
      '<div class="field"><label>Nombre</label><input class="field-input" name="nombre" placeholder="Ej. Jarvis" required></div>'+
      '<div class="field"><label>Dial number (interno)</label><input class="field-input" name="dial_number" placeholder="Número interno del agente"></div>'+
      '<div class="field"><label>DDI público (opcional)</label><input class="field-input" name="ddi" placeholder="Si tiene número público directo"></div>'+
      '<div class="field"><label>IVR de corte (opcional)</label><input class="field-input" name="ivr_corte" placeholder="Si vacío, usa el del cliente"></div>'+
      '<div id="ag-err"></div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px"><button type="button" class="btn btn-ghost" id="agcancel">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div></form></div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimag'); function close(){ scrim.remove(); }
    document.getElementById('xag').addEventListener('click', close);
    document.getElementById('agcancel').addEventListener('click', close);
    document.getElementById('f-ag').addEventListener('submit', async function(e){
      e.preventDefault(); var fd=new FormData(e.target);
      var o={action:'create', client_id:clientId, nombre:(fd.get('nombre')||'').trim(), dial_number:(fd.get('dial_number')||'').trim(), ddi:(fd.get('ddi')||'').trim(), ivr_corte:(fd.get('ivr_corte')||'').trim()};
      if(!o.nombre || (!o.dial_number && !o.ddi)){ showErr('ag-err','Indica el nombre y al menos el dial number o el DDI.'); return; }
      var r=await api('agents.php', o);
      if(r.data&&(r.data.ok||r.data.id)){ close(); toast('Agente agregado'); var c=findClient(clientId); loadFichaAgents(c||{id:clientId}); }
      else showErr('ag-err', emsg(r.data));
    });
  }

  /* ---- Consumo (botón "Actualizar consumo", reutilizado en la pestaña Stats) ---- */
  function bindDatos(){
    var b=document.getElementById('refresh-cdr');
    if(b) b.addEventListener('click', async function(){
      b.disabled=true; b.textContent='Actualizando…';
      var r=await api('metering.php',{});
      b.disabled=false;
      if(r.status===200){ toast('Consumo actualizado'); await reloadClients(); renderView(); }
      else toast(emsg(r.data));
    });
  }

  /* ---- Stats ---- */
  function donutSVG(pct){
    var sz=96, sw=11, r=(sz-sw)/2, c=2*Math.PI*r, p=Math.max(0,Math.min(100,pct)), off=c*(1-p/100);
    var col = pct>=100?'var(--c-danger)':pct>=75?'var(--c-warn)':'var(--c-ok)';
    return '<svg width="'+sz+'" height="'+sz+'" viewBox="0 0 '+sz+' '+sz+'">'+
      '<g transform="rotate(-90 '+(sz/2)+' '+(sz/2)+')">'+
      '<circle cx="'+(sz/2)+'" cy="'+(sz/2)+'" r="'+r+'" fill="none" stroke="var(--surface-3)" stroke-width="'+sw+'"/>'+
      '<circle cx="'+(sz/2)+'" cy="'+(sz/2)+'" r="'+r+'" fill="none" stroke="'+col+'" stroke-width="'+sw+'" stroke-linecap="round" stroke-dasharray="'+c.toFixed(1)+'" stroke-dashoffset="'+off.toFixed(1)+'"/></g>'+
      '<text x="'+(sz/2)+'" y="'+(sz/2)+'" text-anchor="middle" dominant-baseline="central" style="font-family:var(--font-display);font-weight:700;font-size:21px;fill:'+col+'">'+pct+'%</text></svg>';
  }
  function donutBig(pct){
    var sz=140, sw=16, r=(sz-sw)/2, c=2*Math.PI*r, p=Math.max(0,Math.min(100,pct)), off=c*(1-p/100), col=stCol(pct);
    return '<svg width="'+sz+'" height="'+sz+'" viewBox="0 0 '+sz+' '+sz+'" style="flex:none">'+
      '<g transform="rotate(-90 '+(sz/2)+' '+(sz/2)+')">'+
      '<circle cx="'+(sz/2)+'" cy="'+(sz/2)+'" r="'+r+'" fill="none" stroke="var(--track)" stroke-width="'+sw+'"/>'+
      '<circle cx="'+(sz/2)+'" cy="'+(sz/2)+'" r="'+r+'" fill="none" stroke="'+col+'" stroke-width="'+sw+'" stroke-linecap="round" stroke-dasharray="'+c.toFixed(1)+'" stroke-dashoffset="'+off.toFixed(1)+'"/></g>'+
      '<text x="'+(sz/2)+'" y="'+(sz/2)+'" text-anchor="middle" dominant-baseline="central" style="font-family:var(--font-display);font-weight:700;font-size:30px;fill:'+col+'">'+pct+'%</text></svg>';
  }
  function mdClients(){
    var l=S.clients.slice().sort(function(a,b){return b.porcentaje-a.porcentaje;});
    if(S.mdFilter==='aviso') l=l.filter(function(c){return c.porcentaje>=75&&c.porcentaje<100;});
    else if(S.mdFilter==='cortados') l=l.filter(function(c){return c.porcentaje>=100;});
    return l;
  }
  function viewStats(){
    var admin=isAdmin(), list=mdClients();
    if((S.mdSel==null || !findClient(S.mdSel)) && list.length) S.mdSel=list[0].id;
    var items=list.map(function(c){ var k=stk(c.porcentaje);
      return '<button class="md-item'+(c.id===S.mdSel?' sel':'')+'" data-sel="'+c.id+'">'+
        '<div class="md-ava '+k+'">'+esc(initials(c.nombre).slice(0,1))+'</div>'+
        '<div class="md-mid"><div class="md-row1"><span class="md-nm">'+esc(c.nombre)+'</span>'+
        '<span class="md-pct" style="color:'+stCol(c.porcentaje)+'">'+c.porcentaje+'%</span></div>'+
        '<div class="bar '+k+'"><span style="width:'+Math.min(100,c.porcentaje)+'%"></span></div></div></button>';
    }).join('');
    var filt=[['todos','Todos'],['aviso','En aviso'],['cortados','Cortados']].map(function(f){
      return '<button class="md-filter'+(S.mdFilter===f[0]?' active':'')+'" data-filt="'+f[0]+'">'+f[1]+'</button>';
    }).join('');
    return '<div class="view-head"><div><h1 class="vh-title">¿Cómo van tus clientes?</h1>'+
      '<p class="vh-sub">Elige un cliente de la lista para ver su ficha y sus agentes</p></div>'+
      (admin?'<button class="btn btn-primary" id="refresh-cdr">↻ Actualizar consumo</button>':'')+'</div>'+
      '<div class="md"><div class="md-list"><div class="md-list-head"><span class="t">Clientes</span><span class="md-count">'+S.clients.length+'</span></div>'+
      '<div class="md-filters">'+filt+'</div>'+
      '<div class="md-scroll">'+(list.length?items:'<div class="md-empty" style="min-height:120px">Sin clientes en este filtro.</div>')+'</div></div>'+
      '<div class="md-detail" id="md-detail"></div></div>';
  }
  function renderFichaDetail(){
    var box=document.getElementById('md-detail'); if(!box) return;
    var c=findClient(S.mdSel);
    if(!c){ box.innerHTML='<div class="md-empty">Elige un cliente de la lista para ver su ficha.</div>'; return; }
    var pct=c.porcentaje, col=stCol(pct);
    box.innerHTML=
      '<div class="fd-top">'+donutBig(pct)+
      '<div class="fd-head"><h2>'+esc(c.nombre)+'</h2><div class="sub">'+esc(c.sector||'—')+' · '+esc(c.plan||'—')+'</div>'+
      '<div class="fd-fig"><span class="fd-used" style="color:'+col+'">'+c.minutos_usados+'</span><span class="fd-of">/ '+c.minutos_contratados+' min · usados / contratados</span></div>'+
      '<div style="margin-top:8px">'+estadoBadge(pct)+'</div></div></div>'+
      '<div class="kv-grid">'+
      '<div class="kv"><div class="k">Correo</div><div class="v" style="font-family:var(--font-mono);font-size:13px">'+esc(c.correo||'—')+'</div></div>'+
      '<div class="kv"><div class="k">Tenant</div><div class="v" style="font-family:var(--font-mono)">'+esc(c.tenant||'—')+'</div></div>'+
      '<div class="kv"><div class="k">IVR de corte</div><div class="v" style="font-size:13px">'+esc(c.desvio_100||'—')+'</div></div>'+
      '<div class="kv"><div class="k">Minutos restantes</div><div class="v">'+Math.max(0,c.minutos_contratados-c.minutos_usados)+' min</div></div></div>'+
      '<div class="modal-agents" id="ficha-agents"><div class="ma-title">Agentes IA</div><div class="muted" style="padding:6px 0">Cargando…</div></div>';
    loadFichaAgents(c);
  }
  function bindStats(){
    document.querySelectorAll('.md-item[data-sel]').forEach(function(b){ b.addEventListener('click', function(){ S.mdSel=+b.dataset.sel; renderView(); }); });
    document.querySelectorAll('.md-filter[data-filt]').forEach(function(b){ b.addEventListener('click', function(){ S.mdFilter=b.dataset.filt; var l=mdClients(); S.mdSel=l.length?l[0].id:null; renderView(); }); });
    renderFichaDetail();
  }

  /* ---- Avisos (mail) ---- */
  function buildEmail(c){
    var pct=c.porcentaje, rest=Math.max(0,c.minutos_contratados-c.minutos_usados);
    var asunto = pct>=100 ? 'Servicio suspendido · Agente IA — '+c.nombre : 'Consumo de minutos · Agente IA — '+c.nombre+' ('+pct+'%)';
    var datos='· Minutos consumidos: '+c.minutos_usados+' de '+c.minutos_contratados+' min\n· Uso: '+pct+'%\n· Restantes: '+rest+' min\n\n';
    var cuerpo;
    if(pct>=100) cuerpo='Hola,\n\nTe informamos de que tu Agente de Voz IA ha alcanzado el 100% de los minutos contratados y el servicio se ha desviado temporalmente.\n\n'+datos+'Para reactivarlo o ampliar tu bolsa de minutos, responde a este correo.\n\nUn saludo,\nEquipo Conexia';
    else if(pct>=75) cuerpo='Hola,\n\nHas consumido el '+pct+'% de tus minutos del Agente IA y te acercas al límite.\n\n'+datos+'Si prevés más uso, podemos ampliar tu bolsa para evitar interrupciones.\n\nUn saludo,\nEquipo Conexia';
    else cuerpo='Hola,\n\nResumen de consumo de tu Agente de Voz IA.\n\n'+datos+'Tu consumo está dentro de lo previsto.\n\nUn saludo,\nEquipo Conexia';
    return {asunto:asunto, cuerpo:cuerpo};
  }
  function viewMail(){
    if(!S.clients.length) return '<div class="card view-enter"><div class="empty-note">No hay clientes.</div></div>';
    var opts=S.clients.map(function(c){return '<option value="'+c.id+'">'+esc(c.nombre)+'</option>';}).join('');
    return '<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Avisos de consumo</h2>'+
      '<p class="ph-sub">Plantilla automática editable · envía un aviso (o un correo libre) al cliente cuando quieras</p></div>'+
      '<div class="client-select"><label>Cliente</label><div class="select-shell"><select id="mail-cli">'+opts+'</select></div></div></div>'+
      '<div class="mail-grid" id="mail-body"></div></div>';
  }
  function bindMail(){
    var sel=document.getElementById('mail-cli'); if(!sel) return;
    function render(){ var c=findClient(+sel.value); if(!c) return; var m=buildEmail(c); var admin=isAdmin(); var ro=admin?'':' readonly';
      document.getElementById('mail-body').innerHTML=
        '<div class="field"><label>Para</label><input class="field-input" id="mail-to" value="'+esc(c.correo||'')+'" placeholder="correo del destinatario"'+ro+'></div>'+
        '<div class="field"><label>Asunto</label><input class="field-input" id="mail-subject" value="'+esc(m.asunto)+'"'+ro+'></div>'+
        '<div class="field"><label>Mensaje</label><textarea class="field-input" id="mail-msg" style="min-height:200px"'+ro+'>'+esc(m.cuerpo)+'</textarea></div>'+
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px">'+
        '<span class="muted">'+(admin?'Se envía desde Conexia (con el logo) y con copia a SAC.':'Solo un administrador puede enviar correos.')+'</span>'+
        (admin?'<button class="btn btn-primary" id="mail-send">✉ Enviar correo</button>':'')+'</div>';
      var sendBtn=document.getElementById('mail-send'); if(sendBtn) sendBtn.addEventListener('click', sendMail);
    }
    sel.addEventListener('change', render); render();
  }
  async function sendMail(){
    var to=(document.getElementById('mail-to').value||'').trim();
    var subject=(document.getElementById('mail-subject').value||'').trim();
    var body=document.getElementById('mail-msg').value||'';
    if(!to || !subject || !body.trim()){ toast('Completa destinatario, asunto y mensaje.'); return; }
    if(!confirm('¿Enviar este correo a '+to+'?')) return;
    var btn=document.getElementById('mail-send'); btn.disabled=true; var prev=btn.textContent; btn.textContent='Enviando…';
    var r=await api('send_mail.php',{to:to, subject:subject, body:body});
    btn.disabled=false; btn.textContent=prev;
    if(r.data&&r.data.ok) toast('Correo enviado a '+to); else toast('No se pudo enviar: '+emsg(r.data));
  }

  /* ---- Equipo (admin) ---- */
  async function viewEquipo(v){
    v.innerHTML='<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Equipo</h2><p class="ph-sub">Cuentas del equipo técnico</p></div><button class="btn btn-primary" id="add-user">+ Añadir miembro</button></div><div id="users-list" class="cli-table"><div class="empty-note">Cargando…</div></div></div>';
    var r=await api('users.php',{action:'list'});
    var list=document.getElementById('users-list');
    if(r.status!==200 || !r.data.usuarios){ list.innerHTML='<div class="empty-note">'+esc(emsg(r.data))+'</div>'; }
    else {
      list.innerHTML='<div class="cli-head"><span>Miembro</span><span class="hide-sm">Rol</span><span class="r">2FA</span><span class="r">Presencia</span><span class="r">Acciones</span></div>'+
        r.data.usuarios.map(function(u){
          var pres = u.online ? '<span style="color:var(--c-ok);font-weight:600">● En línea</span>' : '<span style="color:var(--text-3)">○ Desconectado</span>';
          var borrar = (u.rol==='admin')
            ? '<span class="icon-btn" title="La cuenta admin no se puede borrar" style="opacity:.4;cursor:not-allowed">🔒</span>'
            : '<button class="icon-btn danger" data-del="'+u.id+'" title="Borrar cuenta">🗑</button>';
          return '<div class="cli-row"><div class="cli-name"><b>'+esc(u.nombre)+'</b><span class="cli-sector">'+esc(u.email)+'</span></div>'+
            '<div class="cli-plan hide-sm">'+esc(u.rol)+'</div>'+
            '<div class="r">'+(u.totp_enabled?'✓':'—')+'</div>'+
            '<div class="r">'+pres+'</div>'+
            '<div class="r cli-actions"><button class="icon-btn" data-r2fa="'+u.id+'" title="Regenerar 2FA">↻2FA</button>'+borrar+'</div></div>';
        }).join('');
    }
    var add=document.getElementById('add-user');
    if(add) add.addEventListener('click', openUserForm);
    v.querySelectorAll('[data-r2fa]').forEach(function(b){ b.addEventListener('click', async function(){
      if(!confirm('¿Regenerar el 2FA de este miembro? Tendrá que volver a escanear el QR en su próximo acceso.')) return;
      var r=await api('users.php',{action:'reset_2fa', id:+b.dataset.r2fa});
      toast(r.status===200?'2FA regenerado':emsg(r.data)); viewEquipo(v);
    });});
    v.querySelectorAll('[data-del]').forEach(function(b){ b.addEventListener('click', async function(){
      if(!confirm('¿Borrar esta cuenta? No se puede deshacer.')) return;
      var r=await api('users.php',{action:'delete', id:+b.dataset.del});
      toast(r.status===200?'Cuenta borrada':emsg(r.data)); viewEquipo(v);
    });});
  }
  function openUserForm(){
    var html='<div class="modal-scrim" id="scrimu"><div class="modal"><div class="modal-head"><div><h3 class="mh-name">Nuevo miembro</h3><div class="mh-meta">Se generará una contraseña temporal</div></div><button class="x-close" id="xu">✕</button></div><div class="modal-body"><form id="f-user">'+
      '<div class="field"><label>Nombre</label><input class="field-input" name="nombre" required></div>'+
      '<div class="field"><label>Email</label><input class="field-input" name="email" type="email" required></div>'+
      '<div class="field"><label>Rol</label><div class="select-shell"><select name="rol"><option value="tecnico">Técnico</option><option value="admin">Admin</option></select></div></div>'+
      '<div id="u-err"></div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px"><button type="button" class="btn btn-ghost" id="ucancel">Cancelar</button><button type="submit" class="btn btn-primary">Crear</button></div></form></div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimu'); function close(){ scrim.remove(); }
    document.getElementById('xu').addEventListener('click', close);
    document.getElementById('ucancel').addEventListener('click', close);
    document.getElementById('f-user').addEventListener('submit', async function(e){
      e.preventDefault(); var fd=new FormData(e.target);
      var r=await api('users.php',{action:'create', nombre:(fd.get('nombre')||'').trim(), email:(fd.get('email')||'').trim(), rol:fd.get('rol')});
      if(r.data && r.data.tmp_pass){ close(); alert('Miembro creado.\n\nContraseña temporal (cópiala y entrégala):\n\n'+r.data.tmp_pass); var v=document.getElementById('view'); viewEquipo(v); }
      else showErr('u-err', emsg(r.data));
    });
  }

  boot();
})();
