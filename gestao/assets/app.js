document.addEventListener('submit', function (event) {
  var form = event.target;
  if (form.matches('form[data-confirm]')) {
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  }
});
