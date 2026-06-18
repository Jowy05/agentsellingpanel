/* ===========================================================================
   DEMO — backend SIMULADO en el navegador. Intercepta fetch('api/...') y
   devuelve datos de EJEMPLO. No hay servidor, ni API keys, ni correos, ni n8n,
   ni conexión con la centralita. Todo es ficticio y se reinicia al recargar.
   app.js se usa SIN modificar.
   =========================================================================== */
(function () {
  'use strict';
  var origFetch = window.fetch.bind(window);
  var periodo = '2026-06';
  var nextCid = 100, nextAid = 900, nextUid = 50;

  var ST = { authed: true };   // demo: arranca ya dentro del panel (para enseñarlo); "Salir" lleva al login
  var USER = { id: 1, nombre: 'Admin Demo', rol: 'admin', email: 'admin@conexia.demo' };

  var clients = [
    { id:1, nombre:'Clínica Dental Sonrisa',     sector:'Salud',        plan:'Plan Recepción 500', correo:'recepcion@sonrisa.demo',     tenant:'4012', minutos_contratados:500,  minutos_usados:180,  desvio_100:'105' },
    { id:2, nombre:'Taller Hnos. García',        sector:'Automoción',   plan:'Plan Citas 300',     correo:'citas@tallergarcia.demo',   tenant:'4055', minutos_contratados:300,  minutos_usados:246,  desvio_100:'120' },
    { id:3, nombre:'Inmobiliaria Vista',         sector:'Inmobiliaria', plan:'Plan Pro 1000',      correo:'info@inmovista.demo',       tenant:'4101', minutos_contratados:1000, minutos_usados:1000, desvio_100:'200' },
    { id:4, nombre:'Restaurante La Parra',       sector:'Hostelería',   plan:'Plan Reservas 200',  correo:'reservas@laparra.demo',     tenant:'4133', minutos_contratados:200,  minutos_usados:92,   desvio_100:'150' },
    { id:5, nombre:'Gestoría Méndez',            sector:'Servicios',    plan:'Plan Office 800',    correo:'contacto@gmendez.demo',     tenant:'4170', minutos_contratados:800,  minutos_usados:616,  desvio_100:'110' },
    { id:6, nombre:'Clínica Veterinaria Patitas',sector:'Salud',        plan:'Plan Básico 400',    correo:'hola@vetpatitas.demo',      tenant:'4202', minutos_contratados:400,  minutos_usados:52,   desvio_100:'105' },
    { id:7, nombre:'Autoescuela Norte',          sector:'Formación',    plan:'Plan Citas 600',     correo:'info@aenorte.demo',         tenant:'4250', minutos_contratados:600,  minutos_usados:588,  desvio_100:'130' }
  ];
  var agents = {
    1: [{ id:11, nombre:'Recepcionista IA Sonrisa', ddi:'910111222', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:180 }],
    2: [{ id:21, nombre:'Agente Citas Taller',      ddi:'930222333', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:246 }],
    3: [{ id:31, nombre:'Asistente Ventas Vista',   ddi:'910333444', dial_number:'', ivr_corte:'', estado_desvio:'cortado', minutos:540 },
        { id:32, nombre:'Filtro Llamadas Vista',    ddi:'910333445', dial_number:'', ivr_corte:'', estado_desvio:'cortado', minutos:460 }],
    4: [{ id:41, nombre:'Reservas La Parra',        ddi:'960444555', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:92 }],
    5: [{ id:51, nombre:'Office IA Méndez',         ddi:'910555666', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:410 },
        { id:52, nombre:'Cobros IA Méndez',         ddi:'910555667', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:206 }],
    6: [{ id:61, nombre:'Recepción Vet Patitas',    ddi:'910666777', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:52 }],
    7: [{ id:71, nombre:'Citas Autoescuela',        ddi:'910777888', dial_number:'', ivr_corte:'', estado_desvio:'normal',  minutos:588 }]
  };
  var team = [
    { id:1, email:'admin@conexia.demo', nombre:'Admin Demo',   rol:'admin',   totp_enabled:1, estado:'activo', online:true,  ultimo_visto:'2026-06-17 09:10:00' },
    { id:2, email:'joel@conexia.demo',  nombre:'Joel Técnico', rol:'tecnico', totp_enabled:1, estado:'activo', online:false, ultimo_visto:'2026-06-16 18:42:00' },
    { id:3, email:'sara@conexia.demo',  nombre:'Sara Soporte', rol:'tecnico', totp_enabled:0, estado:'activo', online:true,  ultimo_visto:'2026-06-17 09:12:00' }
  ];

  function J(status, data) { return { status: status, data: data }; }
  function pct(c) { return c.minutos_contratados ? Math.round(c.minutos_usados / c.minutos_contratados * 100) : 0; }
  function cliFull(c) {
    var p = pct(c);
    var cortados = (agents[c.id] || []).filter(function (a) { return a.estado_desvio === 'cortado'; })
      .map(function (a) { return { id: a.id, nombre: a.nombre, ddi: a.ddi }; });
    return Object.assign({}, c, { porcentaje: p, estado: p >= 100 ? 'cortado' : 'normal', num_agentes: (agents[c.id] || []).length, agentes_cortados: cortados });
  }
  function findCli(id) { for (var i = 0; i < clients.length; i++) if (clients[i].id === +id) return clients[i]; return null; }

  function clientsH(b) {
    var a = b && b.action;
    if (a === 'list') return J(200, { clientes: clients.map(cliFull), periodo: periodo });
    if (a === 'get')  { var c = findCli(b.id); return c ? J(200, { cliente: cliFull(c) }) : J(404, { error: 'no_encontrado' }); }
    if (a === 'create') { var id = ++nextCid; clients.push({ id:id, nombre:b.nombre||'Cliente', correo:b.correo||'', sector:b.sector||'', plan:b.plan||'', tenant:b.tenant||'', minutos_contratados:+b.minutos_contratados||0, minutos_usados:0, desvio_100:b.desvio_100||'' }); agents[id]=[]; return J(201, { ok:true, id:id }); }
    if (a === 'update') { var u = findCli(b.id); if (u) ['nombre','correo','sector','plan','alta','tenant','desvio_100'].forEach(function(k){ if (k in b) u[k]=b[k]; }); if (u && 'minutos_contratados' in b) u.minutos_contratados = +b.minutos_contratados||0; return J(200, { ok:true }); }
    if (a === 'delete') { clients = clients.filter(function(x){ return x.id !== +b.id; }); delete agents[+b.id]; return J(200, { ok:true }); }
    return J(400, { error: 'accion' });
  }
  function agentsH(b) {
    var a = b && b.action, cid = +(b && b.client_id);
    if (a === 'list')   return J(200, { agentes: (agents[cid] || []).slice(), periodo: periodo });
    if (a === 'read')   return J(200, { server: 18, agentes: [{ uuid:'demo-'+cid, nombre:'Agente IA detectado', ddi:'9109'+(cid)+'000', ya_dado_alta:false }] });
    if (a === 'import') { agents[cid] = agents[cid] || []; (b.agentes||[]).forEach(function(x){ agents[cid].push({ id:++nextAid, nombre:x.nombre||'Agente', ddi:x.ddi||'', dial_number:'', ivr_corte:'', estado_desvio:'normal', minutos:0 }); }); return J(200, { ok:true, importados:(b.agentes||[]).length }); }
    if (a === 'create') { agents[cid] = agents[cid] || []; var id=++nextAid; agents[cid].push({ id:id, nombre:b.nombre||'Agente', ddi:b.ddi||'', dial_number:b.dial_number||'', ivr_corte:b.ivr_corte||'', estado_desvio:'normal', minutos:0 }); return J(201, { ok:true, id:id }); }
    if (a === 'update') return J(200, { ok:true });
    if (a === 'delete') { Object.keys(agents).forEach(function(k){ agents[k] = agents[k].filter(function(x){ return x.id !== +b.id; }); }); return J(200, { ok:true }); }
    if (a === 'gui_url') return J(200, { ok:true, url:'#demo-sin-centralita', ddi:'demo', did_id:'71', server:18 });
    return J(400, { error: 'accion' });
  }
  function divertH(b) {
    var found = null;
    Object.keys(agents).forEach(function(k){ agents[k].forEach(function(x){ if (x.id === +b.agent_id) found = x; }); });
    if (!found) return J(404, { error:'agente_no_encontrado' });
    if (b.action === 'cut')        { found.estado_desvio = 'cortado'; return J(200, { ok:true, estado_desvio:'cortado', destino:'114' }); }
    if (b.action === 'reactivado') { found.estado_desvio = 'normal';  return J(200, { ok:true, estado_desvio:'normal' }); }
    return J(400, { error:'accion' });
  }
  function usersH(b) {
    var a = b && b.action;
    if (a === 'list')   return J(200, { usuarios: team.slice() });
    if (a === 'create') { var id=++nextUid; team.push({ id:id, email:b.email||'', nombre:b.nombre||'', rol:b.rol||'tecnico', totp_enabled:0, estado:'activo', online:false, ultimo_visto:null }); return J(201, { ok:true, id:id, tmp_pass:'Demo-Temporal-2026' }); }
    if (a === 'delete') { var t = team.filter(function(x){ return x.id===+b.id; })[0]; if (t && t.rol==='admin') return J(409, { error:'admin_no_borrable' }); team = team.filter(function(x){ return x.id !== +b.id; }); return J(200, { ok:true }); }
    if (a === 'reset_2fa') return J(200, { ok:true });
    return J(400, { error:'accion' });
  }

  function handle(ep, b) {
    switch (ep) {
      case 'session.php':   return ST.authed ? J(200, { user: USER }) : J(401, { error: 'no_auth' });
      case 'login.php':     return J(200, { step: '2fa' });
      case 'verify_2fa.php':ST.authed = true; return J(200, { ok: true, user: USER });
      case 'setup_2fa.php': return J(200, { secret: 'DEMODEMODEMO2345', otpauth_uri: 'otpauth://totp/Conexia%20Demo:admin?secret=DEMODEMODEMO2345&issuer=Conexia%20Demo', step: (b && b.code) ? 'ok' : undefined, recovery_codes: ['DEMO-AA11','DEMO-BB22','DEMO-CC33','DEMO-DD44'] });
      case 'logout.php':    ST.authed = false; return J(200, { ok: true });
      case 'change_pass.php': return J(200, { ok: true });
      case 'send_mail.php': return J(200, { ok: true });
      case 'metering.php':  return J(200, { ok: true, periodo: periodo, resumen: [] });
      case 'clients.php':   return clientsH(b);
      case 'agents.php':    return agentsH(b);
      case 'divert.php':    return divertH(b);
      case 'users.php':     return usersH(b);
      case 'pbx_ping.php':  return J(200, { ok: true, extensiones: 42 });
      default:              return J(404, { error: 'demo: endpoint no simulado' });
    }
  }

  window.fetch = function (url, opt) {
    if (typeof url === 'string' && url.lastIndexOf('api/', 0) === 0) {
      var ep = url.slice(4).split('?')[0];
      var b = null; try { if (opt && opt.body) b = JSON.parse(opt.body); } catch (e) {}
      var r = handle(ep, b);
      return Promise.resolve({ status: r.status, ok: r.status >= 200 && r.status < 300, json: function () { return Promise.resolve(r.data); } });
    }
    return origFetch(url, opt);
  };

  // Distintivo "DEMO" + ayuda de acceso.
  function addBadge() {
    if (document.getElementById('demo-badge')) return;
    var b = document.createElement('div');
    b.id = 'demo-badge';
    b.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:300;background:#13233a;color:#fff;font:12px/1.3 Arial,sans-serif;padding:8px 12px;border-radius:10px;box-shadow:0 8px 22px -8px rgba(0,0,0,.5);max-width:260px';
    b.innerHTML = '<b>DEMO</b> · datos de ejemplo. Sin servidor, sin correos reales. En el login vale <b>cualquier</b> email/contraseña y código.';
    document.body.appendChild(b);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addBadge); else addBadge();
})();
