'use strict';
(() => {
  const dialog = document.getElementById('design-demo-dialog');
  document.getElementById('open-design-demo').addEventListener('click',() => {
    dialog.showModal();
    window.PlanexInterfaceDesign.mount(dialog);
  });
  dialog.querySelectorAll('[data-demo-close]').forEach(button => button.addEventListener('click',() => dialog.close()));
  dialog.querySelectorAll('[data-demo-business]').forEach(button => button.addEventListener('click',() => {
    document.getElementById('design-demo-result').textContent = 'Выбрано действие «'+button.textContent+'».';
    dialog.close();
  }));
  const disclosure = document.querySelector('[aria-controls="design-demo-details"]');
  disclosure.addEventListener('click',() => window.PlanexRegistry.setDisclosure(disclosure,disclosure.getAttribute('aria-expanded')!=='true'));
})();
