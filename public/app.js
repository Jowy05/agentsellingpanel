/* Panel de minutos · Agente IA — frontend (vanilla JS, sin build) */
(function () {
  'use strict';
  var app = document.getElementById('app');

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  var S = { user:null, clients:[], periodo:'', view:'clientes' };
  var ACK_KEY = 'cx_alerts_ack';
  var DOC_TITLE = 'Panel Agentes Voz IA — Conexia';
  var BELL_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 0 0 4 0"/></svg>';
  var titleTimer = null;

  async function api(path, body){
    var opt = { method: body ? 'POST':'GET', credentials:'same-origin', headers:{} };
    if (body){ opt.headers['Content-Type']='application/json'; opt.body=JSON.stringify(body); }
    var res, data;
    try { res = await fetch('api/'+path, opt); } catch(e){ return {status:0, data:{error:'sin conexión con el servidor'}}; }
    try { data = await res.json(); } catch(e){ data = {error:'respuesta no válida'}; }
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

  function renderPanel(){
    var views=[['clientes','Clientes'],['datos','Consumo'],['stats','Stats'],['mail','Avisos']];
    if (isAdmin()) views.push(['equipo','Equipo']);
    app.innerHTML =
      '<div class="app">'+
      '<div class="topbar"><div class="brand"><div class="logo"></div><div class="divider"></div>'+
        '<div class="titles"><div class="kicker">Conexia</div><h1 id="app-name">Panel Agentes Voz IA</h1></div></div>'+
        '<div class="topbar-right"><span class="userchip">'+esc(S.user.nombre)+' · '+esc(S.user.rol)+'</span>'+
        '<div class="notif-wrap"><button class="notif-btn" id="notif" title="Notificaciones" aria-label="Notificaciones">'+BELL_SVG+'<span class="notif-badge" id="notif-badge"></span></button></div>'+
        '<button class="theme-toggle" id="logout">Salir</button></div></div>'+
      '<div class="nav"><div class="nav-segmented">'+views.map(function(v){return '<button class="seg'+(v[0]===S.view?' active':'')+'" data-v="'+v[0]+'">'+v[1]+'</button>';}).join('')+'</div></div>'+
      '<div id="view"></div></div>';
    document.getElementById('logout').addEventListener('click', async function(){ await api('logout.php',{}); renderLogin(); });
    document.getElementById('notif').addEventListener('click', function(e){ e.stopPropagation(); toggleNotif(); });
    app.querySelectorAll('.seg').forEach(function(b){ b.addEventListener('click', function(){ S.view=b.dataset.v; renderView(); }); });
    renderView();
  }

  /* ---- Avisos / notificaciones ---- */
  function computeAlerts(){
    var list = S.clients.filter(function(c){ return c.porcentaje >= 75; })
      .map(function(c){ return { nombre:c.nombre, pct:c.porcentaje, level: c.porcentaje>=100?'danger':'warn' }; })
      .sort(function(a,b){ return b.pct - a.pct; });
    var level = list.some(function(x){ return x.level==='danger'; }) ? 'danger' : (list.length ? 'warn' : null);
    var sig = list.map(function(x){ return x.nombre+':'+x.level; }).join('|');
    return { list:list, level:level, sig:sig };
  }
  function alertsAcked(sig){ try { return localStorage.getItem(ACK_KEY) === sig; } catch(e){ return false; } }
  function ackAlerts(sig){ try { localStorage.setItem(ACK_KEY, sig); } catch(e){} }

  function stopTitleBlink(){ if (titleTimer){ clearInterval(titleTimer); titleTimer = null; } document.title = DOC_TITLE; }

  function updateAlerts(){
    var a = computeAlerts();
    var unacked = a.sig !== '' && !alertsAcked(a.sig);
    var btn = document.getElementById('notif');
    if (btn){ btn.classList.remove('has-alert','warn','danger'); if (a.list.length) btn.classList.add('has-alert', a.level); }
    var badge = document.getElementById('notif-badge');
    if (badge){ badge.textContent = a.list.length ? String(a.list.length) : ''; badge.className = 'notif-badge' + (a.list.length ? (' show' + (a.level==='warn' ? ' warn' : '')) : ''); }
    // Parpadeo en el TÍTULO DE LA PESTAÑA: el "(N)" aparece y desaparece.
    if (titleTimer){ clearInterval(titleTimer); titleTimer = null; }
    if (unacked && a.list.length){
      var pre = '(' + a.list.length + ') ';
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
    var items = a.list.length ? a.list.map(function(x){
      var badge = x.level==='danger'
        ? '<span class="state-badge cortado"><span class="sd"></span>100%</span>'
        : '<span class="state-badge aviso"><span class="sd"></span>Aviso</span>';
      return '<div class="notif-item"><span><b>'+esc(x.nombre)+'</b> · '+x.pct+'%</span>'+badge+'</div>';
    }).join('') : '<div class="notif-empty">Sin avisos. Todo dentro de lo previsto.</div>';
    var dd = document.createElement('div'); dd.className = 'notif-dropdown';
    dd.innerHTML = '<div class="nd-head">Avisos de consumo</div>' + items;
    wrap.appendChild(dd);
    ackAlerts(a.sig);   // abrir el panel = marcar como leído -> deja de parpadear
    updateAlerts();
    setTimeout(function(){ document.addEventListener('click', function onDoc(e){ if (!wrap.contains(e.target)){ dd.remove(); document.removeEventListener('click', onDoc); } }); }, 0);
  }

  function renderView(){
    app.querySelectorAll('.seg').forEach(function(b){ b.classList.toggle('active', b.dataset.v===S.view); });
    var v=document.getElementById('view');
    if (S.view==='clientes'){ v.innerHTML=viewClientes(); bindClientes(); }
    else if (S.view==='datos'){ v.innerHTML=viewDatos(); bindDatos(); }
    else if (S.view==='stats'){ v.innerHTML=viewStats(); }
    else if (S.view==='mail'){ v.innerHTML=viewMail(); bindMail(); }
    else if (S.view==='equipo'){ viewEquipo(v); }
    updateAlerts();
  }

  /* ---- Clientes (CRUD) ---- */
  function viewClientes(){
    var rows = S.clients.map(function(c){
      return '<div class="cli-row">'+
        '<div class="cli-name"><b data-ficha="'+c.id+'">'+esc(c.nombre)+'</b><span class="cli-sector">'+esc(c.sector||'—')+(c.ddi?' · DDI '+esc(c.ddi):'')+(c.tenant?' · tenant '+esc(c.tenant):'')+'</span></div>'+
        '<div class="cli-plan hide-sm">'+esc(c.plan||'—')+'</div>'+
        '<div class="r cli-mins hide-sm">'+c.minutos_contratados+' min</div>'+
        '<div class="r cli-pct" style="color:'+(c.porcentaje>=100?'var(--c-danger)':c.porcentaje>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.porcentaje+'%</div>'+
        '<div class="r cli-actions"><button class="icon-btn" data-edit="'+c.id+'" title="Editar">✎</button>'+
        '<button class="icon-btn danger" data-del="'+c.id+'" title="Eliminar">🗑</button></div></div>';
    }).join('');
    return '<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Clientes</h2>'+
      '<p class="ph-sub">Alta, edición y baja · datos guardados en el servidor</p></div>'+
      '<button class="btn btn-primary" id="add-cli">+ Añadir cliente</button></div>'+
      '<div class="cli-table"><div class="cli-head"><span>Cliente</span><span class="hide-sm">Plan</span><span class="r hide-sm">Contratados</span><span class="r">Uso</span><span class="r">Acciones</span></div>'+
      (S.clients.length?rows:'<div class="cli-empty">No hay clientes. Pulsa «Añadir cliente».</div>')+'</div></div>';
  }
  function bindClientes(){
    document.getElementById('add-cli').addEventListener('click', function(){ openClientForm(null); });
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
      fld('Minutos contratados','minutos_contratados','number','')+
      fld('Alta','alta','text','Ej. Jun 2026')+
      fld('DDI del agente','ddi','text','Número de entrada')+
      fld('Tenant (PBXware)','tenant','text','Ej. 18')+
      fld('Desvío al 100% (IVR/número destino)','desvio_100','text','Destino al llegar al 100%')+
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
      ['nombre','correo','sector','plan','alta','ddi','tenant','desvio_100'].forEach(function(k){ o[k]=(fd.get(k)||'').trim(); });
      o.minutos_contratados = parseInt(fd.get('minutos_contratados')||'0',10)||0;
      if(!o.nombre){ showErr('cli-err','El nombre es obligatorio.'); return; }
      var r=await api('clients.php', o);
      if(r.data && (r.data.ok || r.data.id)){ close(); toast('Cliente guardado'); await reloadClients(); renderView(); }
      else showErr('cli-err', emsg(r.data));
    });
  }

  function openFicha(c){
    if(!c) return;
    var pct=c.porcentaje, restantes=c.minutos_contratados - c.minutos_usados;
    var divert = isAdmin() ? (
      '<div class="modal-agents"><div class="ma-title">Desvío del DID (PBX)</div>'+
      '<div class="cut-hint">DDI '+esc(c.ddi||'—')+' · al 100% se desvía a: <b>'+esc(c.desvio_100||'—')+'</b>. Estado: <b>'+esc(c.estado_desvio||'normal')+'</b></div>'+
      '<div style="display:flex;gap:10px;margin-top:10px">'+
        '<button class="btn btn-ghost" id="do-cut">Cortar (desviar a IVR de corte)</button>'+
        '<button class="btn btn-ghost" id="do-restore">Restaurar</button></div>'+
      '<div class="muted" style="margin-top:8px">Acciona la centralita real (pbxware.did.edit).</div></div>'
    ) : '';
    var html='<div class="modal-scrim" id="scrimf"><div class="modal"><div class="modal-head"><div>'+
      '<h3 class="mh-name">'+esc(c.nombre)+'</h3><div class="mh-meta">'+esc(c.sector||'—')+' · '+esc(c.plan||'—')+'</div></div>'+
      '<button class="x-close" id="xf">✕</button></div><div class="modal-body">'+
      '<div class="total-foot" style="margin:0"><div class="tf-left"><div class="tf-label">Usado / contratados</div>'+
      '<div class="tf-fig"><span class="tf-used" style="color:'+(pct>=100?'var(--c-danger)':pct>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.minutos_usados+'</span><span class="tf-of">/ '+c.minutos_contratados+' min</span></div></div>'+
      '<div class="tf-right"><div class="tf-pct" style="font-size:38px;color:'+(pct>=100?'var(--c-danger)':pct>=75?'var(--c-warn)':'var(--c-ok)')+'">'+pct+'%</div>'+estadoBadge(pct)+'</div></div>'+
      '<div class="kv-grid"><div class="kv"><div class="k">Minutos restantes</div><div class="v">'+restantes+' min</div></div>'+
      '<div class="kv"><div class="k">Correo</div><div class="v" style="font-size:13px;font-family:var(--font-mono)">'+esc(c.correo||'—')+'</div></div>'+
      '<div class="kv"><div class="k">Tenant</div><div class="v" style="font-family:var(--font-mono)">'+esc(c.tenant||'—')+'</div></div>'+
      '<div class="kv"><div class="k">DDI</div><div class="v" style="font-family:var(--font-mono)">'+esc(c.ddi||'—')+'</div></div></div>'+
      divert+'</div></div></div>';
    var wrap=document.createElement('div'); wrap.innerHTML=html; document.body.appendChild(wrap.firstChild);
    var scrim=document.getElementById('scrimf');
    function close(){ scrim.remove(); }
    document.getElementById('xf').addEventListener('click', close);
    scrim.addEventListener('click', function(e){ if(e.target===scrim) close(); });
    if(isAdmin()){
      document.getElementById('do-cut').addEventListener('click', function(){ doDivert(c.id,'cut',close); });
      document.getElementById('do-restore').addEventListener('click', function(){ doDivert(c.id,'restore',close); });
    }
  }
  async function doDivert(id, action, close){
    if(!confirm('Esto cambia el desvío en la CENTRALITA REAL. ¿Continuar?')) return;
    var r=await api('divert.php',{client_id:id, action:action});
    if(r.status===200 && r.data.ok){ toast('Desvío '+(action==='cut'?'aplicado':'restaurado')); close&&close(); await reloadClients(); renderView(); }
    else toast('PBX: '+emsg(r.data));
  }

  /* ---- Consumo ---- */
  function viewDatos(){
    var rows=S.clients.map(function(c){
      return '<div class="cli-row"><div class="cli-name"><b>'+esc(c.nombre)+'</b><span class="cli-sector">'+esc(c.sector||'—')+'</span></div>'+
        '<div class="r cli-mins">'+c.minutos_usados+' / '+c.minutos_contratados+' min</div>'+
        '<div class="r cli-pct" style="color:'+(c.porcentaje>=100?'var(--c-danger)':c.porcentaje>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.porcentaje+'%</div>'+
        '<div class="r">'+estadoBadge(c.porcentaje)+'</div></div>';
    }).join('');
    return '<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Consumo · '+esc(S.periodo)+'</h2>'+
      '<p class="ph-sub">Minutos del periodo actual (del CDR de la centralita)</p></div>'+
      (isAdmin()?'<button class="btn btn-primary" id="refresh-cdr">↻ Actualizar consumo</button>':'')+'</div>'+
      '<div class="cli-table"><div class="cli-head"><span>Cliente</span><span class="r">Usado / contratado</span><span class="r">%</span><span class="r">Estado</span></div>'+
      (S.clients.length?rows:'<div class="cli-empty">No hay clientes.</div>')+'</div></div>';
  }
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
  function viewStats(){
    var n=S.clients.length;
    var totC=S.clients.reduce(function(s,c){return s+c.minutos_contratados;},0);
    var totU=S.clients.reduce(function(s,c){return s+c.minutos_usados;},0);
    var avg= totC? Math.round(totU/totC*100):0;
    var aviso=S.clients.filter(function(c){return c.porcentaje>=75 && c.porcentaje<100;}).length;
    var cort=S.clients.filter(function(c){return c.porcentaje>=100;}).length;
    return '<div class="kpi-row">'+
      '<div class="kpi"><div class="k">Clientes</div><div class="v">'+n+'</div><div class="f">cartera</div></div>'+
      '<div class="kpi"><div class="k">Consumo medio</div><div class="v">'+avg+'<small>%</small></div><div class="f">'+totU+' / '+totC+' min</div></div>'+
      '<div class="kpi"><div class="k">En aviso</div><div class="v">'+aviso+'</div><div class="f">75–99%</div></div>'+
      '<div class="kpi"><div class="k">Cortados</div><div class="v">'+cort+'</div><div class="f">100% · desvío</div></div></div>'+
      '<div class="card"><div class="panel-head"><div><h2 class="ph-title">Consumo por cliente</h2><p class="ph-sub">Periodo '+esc(S.periodo)+'</p></div></div>'+
      '<div class="cli-table">'+ (S.clients.length? S.clients.slice().sort(function(a,b){return b.porcentaje-a.porcentaje;}).map(function(c){
        return '<div class="cli-row"><div class="cli-name"><b>'+esc(c.nombre)+'</b></div><div class="r cli-pct" style="color:'+(c.porcentaje>=100?'var(--c-danger)':c.porcentaje>=75?'var(--c-warn)':'var(--c-ok)')+'">'+c.porcentaje+'%</div><div class="r">'+estadoBadge(c.porcentaje)+'</div></div>';
      }).join('') : '<div class="cli-empty">No hay clientes.</div>')+'</div></div>';
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
      '<p class="ph-sub">Texto auto-generado · el envío real se hará por n8n</p></div>'+
      '<div class="client-select"><label>Cliente</label><div class="select-shell"><select id="mail-cli">'+opts+'</select></div></div></div>'+
      '<div class="mail-grid" id="mail-body"></div></div>';
  }
  function bindMail(){
    var sel=document.getElementById('mail-cli'); if(!sel) return;
    function render(){ var c=findClient(+sel.value); if(!c) return; var m=buildEmail(c);
      document.getElementById('mail-body').innerHTML=
        '<div class="field"><label>Para</label><input class="field-input" value="'+esc(c.correo||'')+'" readonly></div>'+
        '<div class="field"><label>Asunto</label><input class="field-input" value="'+esc(m.asunto)+'"></div>'+
        '<div class="field"><label>Mensaje</label><textarea class="field-input" style="min-height:200px">'+esc(m.cuerpo)+'</textarea></div>'+
        '<div class="muted" style="padding:8px 2px">Maqueta — no se envía ningún correo desde aquí.</div>';
    }
    sel.addEventListener('change', render); render();
  }

  /* ---- Equipo (admin) ---- */
  async function viewEquipo(v){
    v.innerHTML='<div class="card view-enter"><div class="panel-head"><div><h2 class="ph-title">Equipo</h2><p class="ph-sub">Cuentas del equipo técnico</p></div><button class="btn btn-primary" id="add-user">+ Añadir miembro</button></div><div id="users-list" class="cli-table"><div class="empty-note">Cargando…</div></div></div>';
    var r=await api('users.php',{action:'list'});
    var list=document.getElementById('users-list');
    if(r.status!==200 || !r.data.usuarios){ list.innerHTML='<div class="empty-note">'+esc(emsg(r.data))+'</div>'; }
    else {
      list.innerHTML='<div class="cli-head"><span>Miembro</span><span class="hide-sm">Rol</span><span class="r">2FA</span><span class="r">Estado</span><span class="r">Acciones</span></div>'+
        r.data.usuarios.map(function(u){
          return '<div class="cli-row"><div class="cli-name"><b>'+esc(u.nombre)+'</b><span class="cli-sector">'+esc(u.email)+'</span></div>'+
            '<div class="cli-plan hide-sm">'+esc(u.rol)+'</div>'+
            '<div class="r">'+(u.totp_enabled?'✓':'—')+'</div>'+
            '<div class="r">'+esc(u.estado)+'</div>'+
            '<div class="r cli-actions"><button class="icon-btn" data-r2fa="'+u.id+'" title="Resetear 2FA">↻2FA</button>'+
            '<button class="icon-btn" data-toggle="'+u.id+'" data-estado="'+esc(u.estado)+'" title="Activar/desactivar">⏻</button></div></div>';
        }).join('');
    }
    var add=document.getElementById('add-user');
    if(add) add.addEventListener('click', openUserForm);
    v.querySelectorAll('[data-r2fa]').forEach(function(b){ b.addEventListener('click', async function(){
      if(!confirm('¿Resetear el 2FA de este miembro? Tendrá que volver a enrolarlo.')) return;
      var r=await api('users.php',{action:'reset_2fa', id:+b.dataset.r2fa});
      toast(r.status===200?'2FA reseteado':emsg(r.data)); viewEquipo(v);
    });});
    v.querySelectorAll('[data-toggle]').forEach(function(b){ b.addEventListener('click', async function(){
      var act = b.dataset.estado==='activo' ? 'deactivate':'activate';
      var r=await api('users.php',{action:act, id:+b.dataset.toggle});
      toast(r.status===200?'Hecho':emsg(r.data)); viewEquipo(v);
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
