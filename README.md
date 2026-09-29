# Combine Data - CodeIgniter 3 Migration

Migrated from the supplied Next.js application to CodeIgniter 3 for the target server environment (Ubuntu 18.04 / PHP 7.2).

## Business logic

1. Parse the tab-delimited TB file, keeping only `PERIOD_NUM = 6`.
2. Extract branch from the first segment and rincianAkun from the third segment of `CONCATENATED_SEGMENTS`, then join to `mappingEfs.rincianAkun`.
3. Exclude null `coaF1` mappings and aggregate EFS by branch, coaF1, and currency: sum AMOUNT and BASE_AMOUNT, with distinct sorted rincianAkun values.
4. Parse LKP as `f1 | f2 | f3 | f4 | f5 | f6 | f7 | f8`. Group by f5 (branch), f2 (coaF1), and f4 (currency). Sum f6 and f7 only when f8 is `0000`; retain zero-total groups.
5. LEFT JOIN from LKP to EFS on all three keys, excluding branch `0000`, ordered by branch, coaF1, currency. EFS-only groups are omitted; missing EFS values are blank in CSV.
6. Calculate both differences as LKP minus EFS (missing EFS treated as zero), using exact two-decimal string arithmetic.
7. Choose CSV or XLSX in the form. Both formats export 10 columns to `hasil-kombinasi.csv` or `hasil-kombinasi.xlsx`: branch, coaF1, currency, efs_rincianAkun, lkp_ori, efs_ori, selisih_ori, lkp_eqIDR, efs_eqIDR, selisih_eqIDR.

XLSX uses a temporary worksheet file and ZIP packaging to avoid keeping spreadsheet cells in memory. It preserves leading zeroes in identity columns and uses numeric amount cells. PHP ZipArchive is required; the outputs directory must be writable. XLSX is limited to 1,048,575 data rows (plus the header); use CSV for larger results. CSV uses semicolons; import using that delimiter if Excel does not separate columns automatically.

Processing warnings/errors return JSON instead of being included in the download. The browser checks the response type and file signature before downloading.

Run regression checks with `php tests/reconciliation.php`.

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
