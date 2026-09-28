<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Combined Data</title>
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<main class="page">
  <div class="glow"></div>
  <div class="shell">
    <header class="header">
      <div class="brand-row">
        <div class="brand-icon">↑</div>
        <div>
          <h1>Combined Data</h1>
          <p>Gabungkan data LKP dan data bulanan, lalu unduh hasil selisih dalam format Excel.</p>
        </div>
      </div>
      <div class="badges"><span>Output .XLSX</span><span id="readyBadge" class="muted">0/2 file siap</span></div>
    </header>

    <ol class="steps">
      <li id="step1" class="active"><b>1</b><span>Unggah LKP</span></li>
      <li id="step2"><b>2</b><span>Unggah bulanan</span></li>
      <li id="step3"><b>3</b><span>Proses & unduh</span></li>
    </ol>

    <section class="card">
      <div class="card-head"><h2>Unggah berkas</h2><p>Seret file ke kartu, atau klik untuk memilih dari perangkat.</p></div>
      <form id="processForm" action="process" enctype="multipart/form-data">
        <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" value="<?= $this->security->get_csrf_hash() ?>">
        <div class="file-grid">
          <div class="dropzone" data-field="lkpFile" id="dropLkp">
            <div class="file-top"><div><span class="number">01</span><div><strong>File LKP</strong><small>Data LKP.</small></div></div><span id="lkpState" class="state">Belum siap</span></div>
            <p class="format">Format: 20260630, TXT, DAT, CSV</p>
            <div class="file-bottom">
                <label for="lkpInput" class="choose">▣ &nbsp;Pilih file</label>
                <button type="button" class="remove hidden" data-remove="lkpInput">Hapus</button>
                <span id="lkpName">Belum ada file dipilih</span>
            </div>
            <input id="lkpInput" name="lkpFile" type="file" accept=".20260630,.txt,.dat,.csv" hidden>
          </div>

          <div class="dropzone" data-field="tbFile" id="dropTb">
            <div class="file-top"><div><span class="number">02</span><div><strong>File Bulanan</strong><small>Data bulanan per periode.</small></div></div><span id="tbState" class="state">Belum siap</span></div>
            <p class="format">Format: TSV, TXT</p>
            <div class="file-bottom">
                <label for="tbInput" class="choose">▣ &nbsp;Pilih file</label>
                <button type="button" class="remove hidden" data-remove="tbInput">Hapus</button>
                <span id="tbName">Belum ada file dipilih</span>
            </div>
            <input id="tbInput" name="tbFile" type="file" accept=".tsv,.txt" hidden>
          </div>
        </div>
        <div class="form-foot"><p><b>hasil-kombinasi.xlsx</b><br>File hasil akan langsung diunduh setelah proses selesai.</p><button id="submitBtn" class="primary" type="submit" disabled>⇄ &nbsp;Proses & unduh .XLSX</button></div>
      </form>
      <div id="status" class="status hidden"></div>
    </section>

    <section id="stats" class="stats hidden">
      <div class="stats-head"><div><h2>Ringkasan proses</h2><p>Statistik hasil processing dari backend.</p></div><span class="success-pill">Selesai</span></div>
      <div class="stat-grid" id="statGrid"></div>
    </section>
  </div>
</main>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
