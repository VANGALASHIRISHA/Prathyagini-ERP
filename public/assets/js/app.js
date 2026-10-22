function focusScanner(){const el=document.querySelector('[data-scanner]'); if(el) el.focus();}
document.addEventListener('DOMContentLoaded',focusScanner);

// Mobile sidebar drawer: hamburger button toggles .nav-open on <body>.
// No-op on pages without a logged-in nav (navToggle absent).
(function(){
  const btn=document.getElementById('navToggle'),backdrop=document.getElementById('navBackdrop');
  if(!btn)return;
  function closeNav(){document.body.classList.remove('nav-open');btn.setAttribute('aria-expanded','false');}
  function openNav(){document.body.classList.add('nav-open');btn.setAttribute('aria-expanded','true');}
  btn.addEventListener('click',function(){document.body.classList.contains('nav-open')?closeNav():openNav();});
  if(backdrop)backdrop.addEventListener('click',closeNav);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeNav();});
  document.querySelectorAll('.side a').forEach(function(a){a.addEventListener('click',closeNav);});
})();

(function(){const el=document.getElementById('notificationCount');if(!el)return;async function sync(){try{const r=await fetch('api/notification_count.php',{credentials:'same-origin',cache:'no-store'});if(r.ok){const j=await r.json();if(j&&j.ok)el.textContent=j.unread;}}catch(e){}}setInterval(sync,15000);})();

// Camera scanning for existing scan screens: adds a "Camera" button next to a [data-scanner] input.
// A decoded code is put into the input and its form is submitted exactly as a USB scanner would.
const APP_JS_SRC=(document.currentScript&&document.currentScript.src)||(location.origin+'/assets/js/app.js');
const CAMERA_SCAN_PAGES=['online_pick.php','online_pack.php','online_return_receive.php','stock_count.php'];
let cameraScanLoading=null;
function loadCameraScan(){
  if(window.CameraScan) return Promise.resolve(window.CameraScan);
  if(!cameraScanLoading){
    cameraScanLoading=new Promise(function(resolve,reject){
      const s=document.createElement('script');
      s.src=new URL('camera_scan.js',APP_JS_SRC).href;
      s.onload=function(){window.CameraScan?resolve(window.CameraScan):reject(new Error('Camera scanner failed to load.'));};
      s.onerror=function(){cameraScanLoading=null;reject(new Error('Camera scanner failed to load.'));};
      document.head.appendChild(s);
    });
  }
  return cameraScanLoading;
}
function attachCameraScan(inputEl){
  if(!inputEl||inputEl.dataset.cameraAttached) return;
  inputEl.dataset.cameraAttached='1';
  if(!document.querySelector('link[data-scan-css]')){
    const l=document.createElement('link');l.rel='stylesheet';l.href=new URL('../css/scan.css',APP_JS_SRC).href;l.setAttribute('data-scan-css','');document.head.appendChild(l);
  }
  const wrap=document.createElement('div');wrap.className='cs-inline';
  inputEl.parentNode.insertBefore(wrap,inputEl);wrap.appendChild(inputEl);
  const btn=document.createElement('button');
  btn.type='button';btn.className='btn secondary cs-btn';btn.textContent='Camera';
  btn.setAttribute('aria-label','Scan with camera');
  wrap.appendChild(btn);
  btn.addEventListener('click',function(){
    loadCameraScan().then(function(cs){
      const label=inputEl.labels&&inputEl.labels[0]?inputEl.labels[0].textContent:(inputEl.closest('form')&&inputEl.closest('form').querySelector('label')||{}).textContent;
      cs.open({title:(label||'Scan barcode').trim(),onDetect:function(code){
        inputEl.value=code;
        const form=inputEl.form;
        if(!form){inputEl.focus();return;}
        if(form.requestSubmit) form.requestSubmit(); else form.submit();
      }});
    }).catch(function(e){alert(e.message);});
  });
}
document.addEventListener('DOMContentLoaded',function(){
  const page=location.pathname.split('/').pop();
  if(CAMERA_SCAN_PAGES.indexOf(page)===-1) return;
  document.querySelectorAll('input[data-scanner]').forEach(attachCameraScan);
});
