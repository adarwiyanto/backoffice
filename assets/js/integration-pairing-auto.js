(function () {
  'use strict';

  var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-auto-pairing-id]'));
  if (!nodes.length || !window.fetch) return;

  var ids = nodes.map(function (node) { return node.getAttribute('data-auto-pairing-id'); }).filter(Boolean);
  var running = false;
  var stopped = false;

  function postCheck(id) {
    var body = new URLSearchParams();
    body.set('action', 'auto_check_pairing');
    body.set('id', id);
    return fetch('?p=integration', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
      cache: 'no-store'
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    });
  }

  function cycle() {
    if (running || stopped || document.hidden) return;
    running = true;
    var completed = false;
    var chain = Promise.resolve();
    ids.forEach(function (id) {
      chain = chain.then(function () {
        if (completed) return null;
        return postCheck(id).then(function (result) {
          if (result && result.done) completed = true;
          return result;
        });
      });
    });
    chain.then(function () {
      if (completed) {
        stopped = true;
        window.location.replace('?p=integration&notice=' + encodeURIComponent('Pairing selesai otomatis dan koneksi aktif.') + '&notice_type=success');
      }
    }).catch(function () {
      // Gangguan jaringan sementara tidak mengubah status. Siklus berikutnya akan mencoba lagi.
    }).finally(function () {
      running = false;
    });
  }

  cycle();
  window.setInterval(cycle, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) cycle(); });
}());
