<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rekonsiliasi Data</title>
  <link rel="stylesheet"
    href="<?= htmlspecialchars(parse_url(base_url('assets/css/app.css'), PHP_URL_PATH), ENT_QUOTES, 'UTF-8') ?>?v=<?= filemtime(FCPATH . 'assets/css/app.css') ?>">
</head>

<body>
  <main class="page">
    <div class="glow"></div>
    <div class="shell">
      <header class="header">
        <div class="brand-row">
          <div class="brand-icon">↑</div>
          <div>
            <h1>Rekonsiliasi Data</h1>
            <p>Gabungkan data F1 dan data EFS, lalu unduh hasil selisih dalam format Excel atau CSV.</p>
          </div>
        </div>
        <div class="badges"><span>Output .CSV / .XLSX</span><span id="readyBadge" class="muted">0/2 file siap</span>
        </div>
      </header>

      <ol class="steps">
        <li id="step1" class="active"><b>1</b><span>Unggah F1</span></li>
        <li id="step2"><b>2</b><span>Unggah EFS</span></li>
        <li id="step3"><b>3</b><span>Proses & Unduh</span></li>
      </ol>

      <section class="card">
        <div class="card-head">
          <h2>Unggah berkas</h2>
          <p>Seret file atau klik untuk memilih dari perangkat.</p>
        </div>
        <form id="processForm"
          action="<?= htmlspecialchars(parse_url(site_url('process'), PHP_URL_PATH), ENT_QUOTES, 'UTF-8') ?>"
          method="post"
          data-csrf-url="<?= htmlspecialchars(parse_url(site_url('reconciliation/csrf'), PHP_URL_PATH), ENT_QUOTES, 'UTF-8') ?>"
          enctype="multipart/form-data">
          <input id="csrfToken" type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
            value="<?= $this->security->get_csrf_hash() ?>">
          <div class="file-grid">
            <div class="dropzone" data-field="lkpFile" id="dropLkp">
              <div class="file-top">
                <div><span class="number">01</span>
                  <div><strong>File F1</strong><small>Data F1.</small></div>
                </div><span id="lkpState" class="state">Belum siap</span>
              </div>
              <p class="format">Format: 20260630, TXT, DAT, CSV</p>
              <div class="file-bottom">
                <label for="lkpInput" class="choose">▣ &nbsp;Pilih file</label>
                <button type="button" class="remove hidden" data-remove="lkpInput">Hapus</button>
                <span id="lkpName">Belum ada file dipilih</span>
              </div>
              <input id="lkpInput" name="lkpFile" type="file" accept=".20260630,.txt,.dat,.csv" hidden>
            </div>

            <div class="dropzone" data-field="tbFile" id="dropTb">
              <div class="file-top">
                <div><span class="number">02</span>
                  <div><strong>File EFS</strong><small>Data EFS per periode.</small></div>
                </div><span id="tbState" class="state">Belum siap</span>
              </div>
              <p class="format">Format: TSV, TXT</p>
              <div class="file-bottom">
                <label for="tbInput" class="choose">▣ &nbsp;Pilih file</label>
                <button type="button" class="remove hidden" data-remove="tbInput">Hapus</button>
                <span id="tbName">Belum ada file dipilih</span>
              </div>
              <input id="tbInput" name="tbFile" type="file" accept=".tsv,.txt" hidden>
            </div>
          </div>
          <div class="form-foot">
            <div class="download-options">
              <label class="download-label" for="outputFormat">Format unduhan</label>
              <div class="format-select">
                <svg class="format-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="1.7" aria-hidden="true">
                  <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" />
                  <path d="M14 3v6h6M8 13h8M8 17h8M12 13v4" />
                </svg>
                <select id="outputFormat" name="outputFormat" aria-describedby="outputFormatHint">
                  <option value="xlsx">Excel (.xlsx)</option>
                  <option value="csv">CSV (.csv)</option>
                </select>
                <svg class="format-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="2" aria-hidden="true">
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </div>
              <div class="download-filename"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path d="M12 3v12m-5-5 5 5 5-5M5 16v4h14v-4" />
                </svg><span id="outputFilename">hasil-kombinasi.xlsx</span></div>
            </div>
            <div class="download-action">
              <button id="submitBtn" class="primary" type="submit" disabled>⇄ &nbsp;Proses & unduh</button>
            </div>
          </div>
        </form>
      </section>

      <section id="stats" class="stats hidden">
        <div class="stats-head">
          <div>
            <h2>Ringkasan proses</h2>
            <p>Statistik hasil processing dari backend.</p>
          </div><span class="success-pill">Selesai</span>
        </div>
        <h3 class="stat-section-title">Dari File F1</h3>
        <div class="stat-grid" id="statGridF1"></div>
        <h3 class="stat-section-title">Dari File EFS</h3>
        <div class="stat-grid" id="statGridEfs"></div>
        <h3 class="stat-section-title">Gabungan / Hasil</h3>
        <div class="stat-grid" id="statGridResult"></div>
        <h3 class="stat-section-title">Tren Selisih per Hari (Contoh)</h3>
        <div class="chart-container" style="position: relative; height:250px; width:100%; margin-top: 15px; background: #fff; border: 1px solid #e8e4df; border-radius: 12px; padding: 15px;">
          <canvas id="selisihChart"></canvas>
        </div>
      </section>
    </div>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script
    src="<?= htmlspecialchars(parse_url(base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js'), PHP_URL_PATH), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script
    src="<?= htmlspecialchars(parse_url(base_url('assets/js/app.js'), PHP_URL_PATH), ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>"></script>
</body>

</html>