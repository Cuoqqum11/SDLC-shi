document.addEventListener('click',event=>{
  const dismiss=event.target.closest('[data-dismiss]');
  if(dismiss)dismiss.closest('[data-flash]')?.remove();
  const confirmButton=event.target.closest('[data-confirm]');
  if(confirmButton&&!window.confirm(confirmButton.dataset.confirm)){event.preventDefault();event.stopPropagation();}
});
window.setTimeout(()=>document.querySelector('[data-flash]')?.classList.add('flash-fade'),6500);
