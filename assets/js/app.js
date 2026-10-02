(function () {
  'use strict';
  var notification = window.Swal ? Swal.mixin({
    confirmButtonText: 'Mengerti',
    confirmButtonColor: '#ff6e00',
    customClass: { popup: 'app-notification' }
  }) : null;
  var selisihChartInstance = null;
  var processing = false;
  var form = document.getElementById('processForm');
  var outputFormat = document.getElementById('outputFormat');
  outputFormat.addEventListener('change', function () {
    var filenameLabel = document.getElementById('outputFilename');
    if (filenameLabel) filenameLabel.textContent = 'hasil-kombinasi.' + outputFormat.value;
    var hint = document.getElementById('outputFormatHint');
    if (hint) hint.textContent = outputFormat.value === 'xlsx'
      ? 'Format angka dan kode cabang tetap terjaga di Excel.'
      : 'Data teks dengan pemisah titik koma, untuk impor ke aplikasi lain.';
  });
  var submit = document.getElementById('submitBtn');
  var statsBox = document.getElementById('stats');
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
      try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; setFile(field, file); } catch (err) { showNotification('error', 'Browser tidak mengizinkan file drop untuk field ini. Gunakan tombol Pilih file.'); }
    });
  });

  function showNotification(type, message) {
    if (notification) notification.fire({
      icon: type,
      title: type === 'success' ? 'File telah diunduh' : 'Proses belum berhasil',
      text: message,
      confirmButtonText: type === 'success' ? 'Selesai' : 'Mengerti'
    });
  }
  function showProcessing() {
    if (notification) notification.fire({
      title: 'Memproses file…',
      allowOutsideClick: false,
      allowEscapeKey: false,
      showConfirmButton: false,
      didOpen: function () { Swal.showLoading(); }
    });
  }
  function formatNumber(v) { return typeof v === 'number' && isFinite(v) ? new Intl.NumberFormat('id-ID').format(v) : '—'; }
  function nominalCard(label, amount) {
    // Preserve decimal precision for amounts above JavaScript's safe integer limit.
    var formatted = '—';
    if (typeof amount === 'string' && /^-?\d+\.\d{2}$/.test(amount)) {
      var parts = amount.split('.');
      formatted = 'Rp ' + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + parts[1];
    }
    return '<div class="stat"><small>' + label + '</small><strong>' + formatted + '</strong></div>';
  }
  var historyChoice = document.getElementById('historyChoice');
  var historyStatus = '';
  var pendingToken = null;
  var savingHistory = false;

  async function readJsonResponse(response) {
    var text = await response.text();
    var body;
    try { body = JSON.parse(text); }
    catch (err) {
      throw new Error('Server mengirim respons yang bukan JSON (HTTP ' + response.status + '). Periksa log PHP dan izin folder sesi server.');
    }
    if (!body || typeof body !== 'object' || Array.isArray(body))
      throw new Error('Format respons server tidak valid.');
    return body;
  }

  async function refreshHistoryChart() {
    var note = document.getElementById('trendNote');
    try {
      var response = await fetch(historyChoice.dataset.historyUrl, { cache: 'no-store', credentials: 'same-origin' });
      var body = await readJsonResponse(response);
      if (!response.ok) throw new Error(body.message || 'Gagal memuat riwayat.');
      var rows = body.rows || [];
      if (selisihChartInstance) { selisihChartInstance.destroy(); selisihChartInstance = null; }
      if (!rows.length) return;
      if (!window.Chart) { note.textContent += ' Pustaka grafik belum dimuat.'; return; }
      selisihChartInstance = new Chart(document.getElementById('selisihChart'), {
        type: 'line',
        data: {
          labels: rows.map(function (row) { return row.date; }),
          datasets: [{ label: 'Selisih IDR', data: rows.map(function (row) { return Number(row.total_selisih_eqIDR); }),
            borderColor: '#ff6e00', backgroundColor: 'rgba(255,110,0,0.1)', borderWidth: 2,
            fill: true, tension: 0.3, pointRadius: 4 }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: function (context) { return 'Selisih IDR: Rp ' + formatNumber(context.raw); }
          } } },
          scales: { y: { title: { display: true, text: 'Selisih IDR' } }, x: { title: { display: true } } }
        }
      });
    } catch (err) { note.textContent = err.message || 'Gagal memuat chart dari database.'; }
  }

  async function showHistoryChoice(summary) {
    pendingToken = summary.pendingToken || null;
    historyStatus = pendingToken
      ? 'Tanggal data: ' + summary.dataDate + '. Simpan seluruh baris hasil ke tabel riwayat. Data tanggal yang sama akan diganti. Pilihan tersedia selama 2 jam.'
      : (summary.saveUnavailableReason || 'Data tidak tersedia untuk disimpan.');
    if (!notification) return;
    if (!pendingToken) {
      await notification.fire({ icon: 'info', title: 'File telah diunduh', text: historyStatus });
      return;
    }
    async function choose(decision) {
      try {
        await applyHistoryDecision(decision);
        return true;
      } catch (err) {
        Swal.showValidationMessage(err.message || 'Penyimpanan gagal. Silakan coba lagi.');
        return false;
      }
    }
    var result = await notification.fire({
      icon: 'success',
      title: 'File telah diunduh',
      text: 'Simpan hasil tanggal ' + summary.dataDate + ' ke database dan tampilkan di chart? Data tanggal yang sama akan diganti.',
      showDenyButton: true,
      confirmButtonText: 'Simpan ke database',
      denyButtonText: 'Tidak simpan',
      denyButtonColor: '#6b7280',
      allowOutsideClick: false,
      allowEscapeKey: false,
      showLoaderOnConfirm: true,
      showLoaderOnDeny: true,
      preConfirm: function () { return choose('save'); },
      preDeny: function () { return choose('discard'); }
    });
    if (result.isConfirmed || result.isDenied) {
      await notification.fire({
        icon: 'success',
        title: result.isConfirmed ? 'Data berhasil disimpan' : 'Selesai',
        text: historyStatus
      });
    }
  }

  async function applyHistoryDecision(decision) {
    if (savingHistory || !pendingToken) throw new Error('Data belum tersedia atau sedang disimpan.');
    savingHistory = true;
    historyStatus = decision === 'save' ? 'Menyimpan hasil ke database…' : 'Menghapus data sementara…';
    try {
      var tokenResponse = await fetch(form.dataset.csrfUrl, { cache: 'no-store', credentials: 'same-origin' });
      if (!tokenResponse.ok) throw new Error('Gagal memperbarui token keamanan.');
      var token = await readJsonResponse(tokenResponse);
      var data = new FormData();
      data.set(token.name, token.hash);
      data.set('pendingToken', pendingToken);
      data.set('decision', decision);
      var response = await fetch(historyChoice.dataset.saveUrl, { method: 'POST', body: data,
        credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      var body = await readJsonResponse(response);
      if (!response.ok || !body.ok) throw new Error(body.message || 'Pilihan penyimpanan gagal diterapkan.');
      pendingToken = null;
      historyStatus = decision === 'save'
        ? formatNumber(body.savedRows) + ' baris disimpan ke database. Chart diperbarui.'
        : 'Data tidak disimpan ke database dan tidak ditambahkan ke chart.';
      if (decision === 'save') await refreshHistoryChart();
    } catch (err) {
      historyStatus = err.message || 'Penyimpanan gagal. Silakan coba lagi.';
      throw err;
    } finally {
      savingHistory = false;
    }
  }
  function renderStats(s, requestSeconds) {
    var f1Items = [
      ['Baris F1', s.lkpRows], ['Terfilter f8', s.filteredByF8],
    ];
    var efsItems = [
      ['Baris EFS', s.tbRows], ['Baris tanpa mapping akun', s.unmatchedRincianAkun], ['Baris dengan mapping', s.mappedRows]
    ];
    var resultItems = [
      ['Total kelompok hasil', s.resultRows],
      ['Baris Rasionalisasi', s.rasionalisasiRows],
      ['Baris bukan Rasionalisasi', s.nonRasionalisasiRows]
    ];

    function buildHtml(items) {
      return items.map(function (item) { return '<div class="stat"><small>' + item[0] + '</small><strong>' + formatNumber(item[1]) + '</strong></div>'; }).join('');
    }

    document.getElementById('statGridF1').innerHTML = buildHtml(f1Items);
    document.getElementById('statGridEfs').innerHTML = buildHtml(efsItems);
    document.getElementById('statGridResult').innerHTML = buildHtml(resultItems);

    statsBox.classList.remove('hidden');

    var r = s.summary;
    if (r) {
      document.getElementById('statGridEfs').innerHTML += nominalCard('Total EFS dalam IDR', r.efsTotalIDR);
      document.getElementById('statGridResult').innerHTML += buildHtml([
        ['F1 match dengan EFS', r.f1MatchedGroups],
        ['F1 tidak match dengan EFS', r.f1UnmatchedGroups],
        ['EFS tanpa pasangan F1', r.efsOnly]
      ]) + nominalCard('Total gabungan F1 + EFS (IDR)', r.combinedTotalIDR);
    }

    // var durations = [['Upload sampai hasil diterima', requestSeconds]];
    // if (s.timingsSeconds) {
    //   var t = s.timingsSeconds;
    //   durations.push(['Pengolahan di server', t.total], ['Mapping akun', t.mapping],
    //     ['Data LKP', t.lkp], ['Data bulanan', t.tb], ['Pengurutan & ekspor', t.sortAndExport]);
    //   // The remainder includes transfer, queuing, and work outside the service.
    //   // It must not be presented as upload time alone.
    //   durations.push(['Transfer & waktu lainnya', Math.max(0, requestSeconds - t.total)]);
    // }
    // durations.forEach(function (item) {
    //   if (typeof item[1] !== 'number' || !isFinite(item[1])) return;
    //   var cell = document.createElement('div');
    //   cell.className = 'stat';
    //   var label = document.createElement('small');
    //   label.textContent = item[0];
    //   var value = document.createElement('strong');
    //   value.textContent = formatNumber(Math.round(item[1] * 10) / 10) + ' detik';
    //   cell.appendChild(label);
    //   cell.appendChild(value);
    //   document.getElementById('statGridResult').appendChild(cell);
    // });

    statsBox.classList.remove('hidden');

  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (processing) return;
    statsBox.classList.add('hidden');
    if (!inputs.lkpFile.files.length || !inputs.tbFile.files.length) { showNotification('error', 'Silakan upload kedua file terlebih dahulu.'); return; }
    processing = true;
    showProcessing();
    var selectedFormat = outputFormat.value;
    outputFormat.disabled = true;
    try {
      // Fetch the current token/cookie pair, including after earlier POSTs or another tab.
      var tokenResponse = await fetch(form.dataset.csrfUrl, { cache: 'no-store', credentials: 'same-origin' });
      if (!tokenResponse.ok) throw new Error('Gagal memperbarui token keamanan. Muat ulang halaman.');
      var token = await readJsonResponse(tokenResponse);
      var tokenInput = document.getElementById('csrfToken');
      tokenInput.name = token.name;
      tokenInput.value = token.hash;
      var url = form.getAttribute('action') || window.location.href;
      var data = new FormData(form);
      data.set('outputFormat', selectedFormat);
      var requestStarted = performance.now();
      var response = await fetch(url, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) {
        var body = await readJsonResponse(response).catch(function () { return null; });
        throw new Error(body && body.message ? body.message : (response.status === 403 ? 'Token keamanan ditolak. Silakan coba proses kembali.' : 'Gagal memproses file (HTTP ' + response.status + ').'));
      }
      var contentType = (response.headers.get('Content-Type') || '').toLowerCase();
      var expectedType = selectedFormat === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv';
      if (contentType.indexOf(expectedType) !== 0 || !(response.headers.get('Content-Disposition') || '').includes('attachment')) {
        throw new Error('Server tidak mengirim file hasil yang valid. Respons mungkin berisi error PHP.');
      }
      var header = response.headers.get('X-Processing-Stats');
      var blob = await response.blob();
      var requestSeconds = (performance.now() - requestStarted) / 1000;
      var signature = await blob.slice(0, 256).text();
      if ((selectedFormat === 'xlsx' && signature.slice(0, 2) !== 'PK') ||
        (selectedFormat === 'csv' && signature.replace(/^\uFEFF/, '').indexOf('branch;coaF1;currency;') !== 0)) {
        throw new Error('Isi file hasil tidak valid atau mengandung error PHP. Unduhan dibatalkan.');
      }
      var filename = 'hasil-kombinasi.' + selectedFormat;
      var url = URL.createObjectURL(blob); var anchor = document.createElement('a');
      anchor.href = url; anchor.download = filename; document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
      // Clear uploaded selections once the valid download has been triggered.
      // Keep the processing summary visible for review.
      Object.keys(inputs).forEach(function (field) {
        inputs[field].value = '';
        setFile(field, null);
      });
      var summary = {};
      if (header) { try { summary = JSON.parse(decodeURIComponent(header)); } catch (ignore) { } }
      renderStats(summary, requestSeconds);
      await showHistoryChoice(summary);
    } catch (err) {
      var message = err && err.message ? err.message : 'Terjadi kesalahan saat memproses data.';
      if (err && err.name === 'TypeError' && /fetch|network|load failed/i.test(message)) {
        message = 'Koneksi ke server terputus sehingga hasil belum diterima. Pastikan jaringan atau VPN tersambung dan komputer tidak sleep. Proses di server mungkin masih berjalan; tunggu sebelum mencoba kembali.';
      }
      showNotification('error', message);
    }
    finally { processing = false; outputFormat.disabled = false; submit.disabled = !inputs.lkpFile.files.length || !inputs.tbFile.files.length; submit.textContent = submit.dataset.original || '⇄  Proses & unduh'; }
  });

  // Render chart when DOM is loaded
  document.addEventListener("DOMContentLoaded", function () {
    refreshHistoryChart();
  });
})();
