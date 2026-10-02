# Combine Data - CodeIgniter 3 Migration

Migrated from the supplied Next.js application to CodeIgniter 3 for the target server environment (Ubuntu 18.04 / PHP 7.2).

## Business logic

1. Parse the tab-delimited TB file, keeping only `PERIOD_NUM = 6`.
2. Extract branch from the first segment and rincianAkun from the third segment of `CONCATENATED_SEGMENTS`, then join to `mappingEfs.rincianAkun`.
3. Exclude null `coaF1` mappings and aggregate EFS by branch, coaF1, and currency: sum AMOUNT and BASE_AMOUNT, with distinct sorted rincianAkun values.
4. Parse LKP as `f1 | f2 | f3 | f4 | f5 | f6 | f7 | f8`. Keep only rows with f8 = `0000` before grouping by f5 (branch), f2 (coaF1), and normalized f4 (currency), then sum f6 and f7.
5. Normalize EFS and LKP currency with UPPER(TRIM(currency)). Combine LKP LEFT JOIN EFS with EFS-only groups (UNION ALL), matching branch, coaF1, and currency. Exclude branch `0000` from both sides and order the combined result by those three keys. Currency mismatches remain as separate rows.
6. For matching groups, calculate LKP minus EFS using exact two-decimal string arithmetic. LKP-only groups have NULL EFS values and differences. EFS-only groups have NULL LKP values and differences of 0 minus EFS. NULL values export as blank cells in CSV/XLSX.
7. Read `GL_Rasionalisasi` (`SL F1`, `Status`) from the configured database. If any row for a coaF1 has `UPPER(TRIM(Status)) = RASIONALISASI`, export both differences as NULL, including EFS-only groups. Keep source amounts unchanged.
8. Choose CSV or XLSX in the form. Both formats export 10 columns to `hasil-kombinasi.csv` or `hasil-kombinasi.xlsx`: branch, coaF1, currency, efs_rincianAkun, lkp_ori, efs_ori, selisih_ori, lkp_eqIDR, efs_eqIDR, selisih_eqIDR.

XLSX uses a temporary worksheet file and ZIP packaging to avoid keeping spreadsheet cells in memory. It preserves leading zeroes in identity columns and uses numeric amount cells. PHP ZipArchive is required; the outputs directory must be writable. XLSX is limited to 1,048,575 data rows (plus the header); use CSV for larger results. CSV uses semicolons; import using that delimiter if Excel does not separate columns automatically.

Processing warnings/errors return JSON instead of being included in the download. The browser checks the response type and file signature before downloading.

After processing, choose whether to save the result to the database. Saving creates
`reconciliation_history` in the configured database and stores all result rows,
including rationalization status. Its `data_date` comes from the first LKP column
(f1), accepting YYYYMMDD, YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, or DDMMYYYY.
All rows in an upload must have one date; invalid or mixed dates still allow the
file download but disable database saving. Saving replaces existing results for
that date in a transaction. The chart shows SUM(selisih_eqIDR) by date from saved
results only, excluding NULL differences for rationalized accounts. Choosing not
to save removes the temporary results and leaves the chart unchanged.

Pending results expire after two hours and belong to the current browser session.
Expired files are cleaned on the next processing or save/discard request.
Deploy `outputs/pending/.htaccess` to deny direct access to temporary data; the
folder must be writable. Session files use `outputs/sessions`, which must also
be writable by the PHP/Apache user. Deploy its `.htaccess` to block direct access. The database user needs CREATE, SELECT, INSERT, and
DELETE permissions. Database tables are created only when saving is selected.

Run regression checks with `php tests/reconciliation.php` and `php tests/history.php`.

Run the synthetic performance check with `php -d memory_limit=512M tests/benchmark_reconciliation.php`.
It processes 520,010 rows per input and verifies the worksheet content hash for
158,470 result rows. XLSX uses DEFLATE level 1 to reduce packaging time at the
cost of a larger download. Backend timings exclude upload/download, web-server
queuing, and (in this synthetic benchmark) the real database mapping query;
measure production requests before treating a runtime target as guaranteed.
Pass `--extended-precision` to exercise decimal exports with trailing precision;
the expected worksheet hash stays the same.

For production performance, check the `runtime` object in `x-processing-stats`
from an actual web request (CLI may use different PHP settings). If Xdebug is
loaded, benchmark with its `zend_extension` entry disabled in the PHP-FPM INI
configuration and restart the applicable FPM service. Enable installed OPcache
in that same runtime (`opcache.enable=1`). These are server configuration changes,
not application `ini_set` changes. Compare the same files and output format before
and after; Xdebug being loaded alone does not prove it caused all the latency.

## Requirements

- PHP 7.2.x
- MySQL / MariaDB with `mysqli`
- PHP extensions required by PhpSpreadsheet 1.14.1: `ctype`, `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`.
- Apache with `mod_rewrite` recommended.
- Composer for installing dependencies.

## Install

1. Upload the project.
2. Copy `.env.example` to `.env` and set DB credentials.
3. Run `composer install` in the project directory.
4. Ensure `uploads/` and `outputs/` are writable by the web server.
5. If the host ignores `user.ini`, ask the server administrator to set upload/post limits to at least 160M/320M.
6. Open the project URL.

The application does not require Node.js in production.
