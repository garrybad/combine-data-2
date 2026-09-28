(function () {
  'use strict';
  var form = document.getElementById('processForm');
  var submit = document.getElementById('submitBtn');
  var status = document.getElementById('status');
  var statsBox = document.getElementById('stats');
  var statGrid = document.getElementById('statGrid');
  var readyBadge = document.getElementById('readyBadge');
  var inputs = { lkpFile: document.getElementById('lkpInput'), tbFile: document.getElementById('tbInput') };

  function bytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  }
  function setFile(field, file) {
    var prefix = field === 'lkpFile' ? 'lkp' : 'tb';
    document.getElementById(prefix + 'Name').textContent = file ? file.name + ' · ' + bytes(file.size) : 'Belum ada file dipilih';
    document.getElementById(prefix + 'State').textContent = file ? 'Siap' : 'Belum siap';
    document.getElementById(prefix + 'State').className = 'state ' + (file ? 'ready' : '');
    document.querySelector('[data-remove="' + (field === 'lkpFile' ? 'lkpInput' : 'tbInput') + '"]').classList.toggle('hidden', !file);
    document.querySelector('[data-field="' + field + '"]').classList.toggle('selected', !!file);
    var count = (inputs.lkpFile.files.length ? 1 : 0) + (inputs.tbFile.files.length ? 1 : 0);
    readyBadge.textContent = count + '/2 file siap';
    submit.disabled = count !== 2;
    document.getElementById('step1').className = inputs.lkpFile.files.length ? 'done' : 'active';
    document.getElementById('step2').className = inputs.tbFile.files.length ? 'done' : (inputs.lkpFile.files.length ? 'active' : '');
    document.getElementById('step3').className = count === 2 ? 'active' : '';
  }
  function choose(field) {
    inputs[field].click();
  }
  Object.keys(inputs).forEach(function (field) {
    inputs[field].addEventListener('change', function () { setFile(field, this.files[0] || null); });
  });

  document.querySelectorAll('.remove').forEach(function (button) { button.addEventListener('click', function () { var input = document.getElementById(this.getAttribute('data-remove')); input.value = ''; setFile(input.name, null); }); });

  document.querySelectorAll('.dropzone').forEach(function (zone) {
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('dragging'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('dragging'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault(); zone.classList.remove('dragging');
      var file = e.dataTransfer.files && e.dataTransfer.files[0]; if (!file) return;
      var field = zone.getAttribute('data-field'); var input = inputs[field];
      try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; setFile(field, file); } catch (err) { alert('Browser tidak mengizinkan file drop untuk field ini. Gunakan tombol Pilih file.'); }
    });
  });

  function showStatus(type, message) { status.className = 'status ' + type; status.textContent = message; }
  function hideStatus() { status.className = 'status hidden'; status.textContent = ''; }
  function formatNumber(v) { return new Intl.NumberFormat('id-ID').format(v); }
  function renderStats(s) {
    var items = [
      ['Baris TB', s.tbRows], ['Mapped', s.mappedRows], ['Tidak match akun', s.unmatchedRincianAkun],
      ['Tidak match COA', s.unmatchedCoaF1], ['Terfilter f5', s.filteredByF5], ['Hasil', s.resultRows],
      ['Duplikat mapping', s.duplicateMappingKeys], ['Duplikat LKP f2', s.duplicateLkpF2Keys]
    ];
    statGrid.innerHTML = items.map(function (item) { return '<div class="stat"><small>' + item[0] + '</small><strong>' + formatNumber(item[1]) + '</strong></div>'; }).join('');
    statsBox.classList.remove('hidden');
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault(); hideStatus(); statsBox.classList.add('hidden');
    if (!inputs.lkpFile.files.length || !inputs.tbFile.files.length) { showStatus('error', 'Silakan upload kedua file terlebih dahulu.'); return; }
    submit.disabled = true; submit.dataset.original = submit.textContent; submit.textContent = 'Memproses data…';
    try {
      var url = form.getAttribute('action') || window.location.href;
      var response = await fetch(url, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) {
        var body = await response.json().catch(function () { return null; });
        throw new Error(body && body.message ? body.message : 'Gagal memproses file.');
      }
      var header = response.headers.get('X-Processing-Stats');
      var blob = await response.blob();
      var url = URL.createObjectURL(blob); var anchor = document.createElement('a');
      anchor.href = url; anchor.download = 'hasil-kombinasi.csv'; document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
      if (header) { try { renderStats(JSON.parse(decodeURIComponent(header))); } catch (ignore) { } }
      showStatus('success', 'Proses selesai. File hasil-kombinasi.csv sudah diunduh.');
    } catch (err) { showStatus('error', err && err.message ? err.message : 'Terjadi kesalahan saat memproses data.'); }
    finally { submit.disabled = false; submit.textContent = submit.dataset.original || '⇄  Proses & unduh .CSV'; }
  });
})();
