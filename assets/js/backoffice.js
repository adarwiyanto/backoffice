document.addEventListener('DOMContentLoaded',()=>{
  const b=document.getElementById('hamb'),s=document.getElementById('sidebar');
  if(b&&s)b.addEventListener('click',()=>s.classList.toggle('open'));

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
