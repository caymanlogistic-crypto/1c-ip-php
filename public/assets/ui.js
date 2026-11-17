'use strict';
document.addEventListener('click', event => {
  const folder = event.target.closest('[data-folder-toggle]');
  if(folder){event.preventDefault();const expanded=folder.getAttribute('aria-expanded')!=='true';folder.setAttribute('aria-expanded',String(expanded));folder.classList.toggle('is-open',expanded);folder.nextElementSibling.classList.toggle('is-open',expanded);folder.nextElementSibling.inert=!expanded;}
  const opener=event.target.closest('[data-dialog]');if(opener)document.getElementById(opener.dataset.dialog)?.showModal();
  if(event.target.closest('[data-dialog-close]'))event.target.closest('dialog')?.close();
});
