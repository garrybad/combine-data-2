# Combine Data - CodeIgniter 3 Migration

Migrated from the supplied Next.js application to CodeIgniter 3 for the target server environment (Ubuntu 18.04 / PHP 7.2).

## Business logic preserved

1. Parse TB Juni tab-delimited file and extract `rincianAkun_tb` from segment index 2.
2. Match `rincianAkun_tb` to `mappingEfs.rincianAkun`.
3. Match `mappingEfs.coaF1` to LKP `f2`.
4. Only LKP rows with `f5 = '0000'` are included.
5. LKP physical layout is `f1 | f2 | f3 | f4 | f5 | unused/f6 | f7 | f8`; the sixth physical field is intentionally skipped.
6. Calculate `selisih = BASE_AMOUNT - f7` with exact two-decimal string arithmetic.
7. Export the same 15 output columns to `hasil-kombinasi.xlsx`.

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
