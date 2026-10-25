// Aviso de cookies (LGPD). O Google Analytics só grava cookies depois do "Aceitar";
// até lá ele roda no modo de consentimento negado. A escolha fica salva neste navegador.
(function(){
  var css = document.createElement('style');
  css.textContent = ':focus-visible{outline:2px solid #C9933A;outline-offset:2px}' +
    '.mr-ck{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:720px;margin:0 auto;background:#111820;border:1px solid #243040;border-radius:4px;padding:16px 18px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;box-shadow:0 12px 40px rgba(0,0,0,.5);font-family:"DM Sans",system-ui,sans-serif}' +
    '.mr-ck p{flex:1 1 320px;margin:0;font-size:14px;line-height:1.5;color:#A0B2C4}.mr-ck a{color:#E8B86D}' +
    '.mr-ck button{font:700 12px "DM Sans",system-ui,sans-serif;letter-spacing:.12em;text-transform:uppercase;padding:11px 16px;border-radius:2px;cursor:pointer;border:1px solid #243040;background:none;color:#EEE9E2}' +
    '.mr-ck button.ok{background:#C9933A;border-color:#C9933A;color:#090C10}';
  document.head.appendChild(css);
  var escolha = null;
  try { escolha = localStorage.getItem('mr-cookies'); } catch (e) {}
  if (escolha) return;
  function mostrar(){
    var b = document.createElement('div');
    b.className = 'mr-ck'; b.setAttribute('role', 'dialog'); b.setAttribute('aria-label', 'Aviso de cookies');
    b.innerHTML = '<p>Usamos cookies do Google Analytics para entender como o site é usado e melhorar o conteúdo. Você escolhe. <a href="/privacidade/">Política de privacidade</a></p>' +
      '<button type="button" data-v="nao">Recusar</button><button type="button" class="ok" data-v="sim">Aceitar</button>';
    b.addEventListener('click', function(e){
      var v = e.target.getAttribute && e.target.getAttribute('data-v'); if (!v) return;
      try { localStorage.setItem('mr-cookies', v); } catch (err) {}
      if (v === 'sim' && typeof gtag === 'function') gtag('consent', 'update', {analytics_storage: 'granted'});
      b.remove();
    });
    document.body.appendChild(b);
  }
  if (document.body) mostrar(); else document.addEventListener('DOMContentLoaded', mostrar);
})();
