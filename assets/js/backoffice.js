document.addEventListener('DOMContentLoaded',()=>{
  const b=document.getElementById('hamb'),s=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay');
  const isMobile=()=>window.matchMedia('(max-width: 960px)').matches;
  const setSidebar=(open)=>{
    if(!s)return;
    s.classList.toggle('open',open);
    document.body.classList.toggle('sidebar-open',open&&isMobile());
    if(b){
      b.setAttribute('aria-expanded',open?'true':'false');
      b.setAttribute('aria-label',open?'Minimalkan menu':'Buka menu');
    }
    if(overlay)overlay.setAttribute('aria-hidden',open?'false':'true');
  };
  if(b&&s)b.addEventListener('click',()=>setSidebar(!s.classList.contains('open')));
  if(overlay)overlay.addEventListener('click',()=>setSidebar(false));
  if(s){
    s.querySelectorAll('a[href]').forEach(link=>link.addEventListener('click',()=>{
      if(isMobile())setSidebar(false);
    }));
  }
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'&&s?.classList.contains('open')&&isMobile())setSidebar(false);
  });
  window.addEventListener('resize',()=>{
    if(!isMobile())setSidebar(false);
  });

  document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{
    if(!confirm(el.dataset.confirm))e.preventDefault();
  }));

  document.querySelectorAll('form[method="post" i]').forEach(form=>form.addEventListener('submit',event=>{
    const btn=event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement
      ? event.submitter
      : form.querySelector('button[type="submit"],button:not([type]),input[type="submit"]');
    if(!btn||btn.disabled)return;

    // A disabled submitter is excluded from the native POST payload. Preserve its name/value
    // first, because API Center stores the requested action on the clicked button itself.
    if(btn.name){
      let carrier=form.querySelector('input[type="hidden"][data-submitter-carrier="1"]');
      if(!carrier){
        carrier=document.createElement('input');
        carrier.type='hidden';
        carrier.dataset.submitterCarrier='1';
        form.appendChild(carrier);
      }
      carrier.name=btn.name;
      carrier.value=btn.value;
    }

    btn.disabled=true;
    btn.classList.add('is-loading');
    btn.dataset.originalText=btn instanceof HTMLInputElement?btn.value:btn.textContent;
    if(btn instanceof HTMLInputElement)btn.value='Memproses…'; else btn.textContent='Memproses…';
  }));
});
