# scan2json

scan2json is a single-file PHP utility that scans a web project under `DOCUMENT_ROOT`, reads each text file, and exports the results to JSONL or JSON. It is handy for preparing project snapshots for LLM prompts, code review aids, or other analysis tools that prefer structured text.

The script ships with a simple Bootstrap UI and works entirely through the browser. Scanning runs in small AJAX chunks to avoid timeouts and to handle large projects safely.

## Features
- Recursive scan of a chosen directory under `DOCUMENT_ROOT`.
- Skips files and folders via prefix, filename, and directory exclusion lists; optional symlink skipping.
- Detects binary files (null-byte check) and skips them automatically.
- Writes results to JSONL (one object per line) and also builds a JSON array file.
- Splits JSON output into parts when the token/word limit is exceeded and optionally zips the parts for easy download.
- Provides ZIP downloads for JSONL, JSON, and JSON parts for convenience.
- Progress is handled through multiple AJAX requests to prevent long-running timeouts.
- Simple Bootstrap-based UI for selecting a folder manually or by navigating the directory tree.
- Access control via Bitrix admin check or a password prompt.

## Requirements
- PHP 7.4+ with `ZipArchive` enabled.
- A web server that can serve PHP (Apache, Nginx + PHP-FPM, etc.) with access to the project `DOCUMENT_ROOT`.
- Optional Bitrix environment if `ACCESS_BITRIX` is enabled.

## Installation
- Clone this repository.
- Copy `scan.php` into a folder under your web server’s `DOCUMENT_ROOT` (for example the site root).
- Ensure the web server user can create and write to `scan_tmp` inside `DOCUMENT_ROOT` (the script will create it automatically when needed).
- Open `scan.php` in a browser; the UI is designed for HTTP access rather than CLI usage.

## Configuration
Adjust the settings at the top of `scan.php` to fit your environment:
- `ACCESS_BITRIX`: `true` to allow only Bitrix admins (requires Bitrix prolog), `false` to use password-based access.
- `ACCESS_PASSWORD`: Password required when `ACCESS_BITRIX` is `false` (the UI shows a warning if the default `123456` remains set).
- `CHUNK_SIZE`: How many files are read in a single AJAX request.
- `TOKEN_MAX`: Approximate maximum word count per JSON part; JSON output is split if this limit is exceeded.
- `$SKIP_SYMLINKS`: When `true`, symbolic links are skipped during traversal.
- `$excludePrefixes`: File or directory name prefixes to skip (e.g., `_`, `test`).
- `$excludeFiles`: Specific filenames to skip anywhere in the tree.
- `$excludeDirs`: Directory names to skip entirely.
- `DEBUG_MODE`: `true` to print the debug log at the bottom of the page.

## Usage
- Authenticate:
  - If `ACCESS_BITRIX` is `true`, log in as a Bitrix admin; non-admins receive HTTP 403.
  - If `ACCESS_BITRIX` is `false`, enter the password to proceed.
- Choose the folder to scan:
  - Manual input: enter a path relative to `DOCUMENT_ROOT` such as `/`, `/local`, `/bitrix/templates`.
  - Dropdown navigation: click through subfolders using the “select from list” mode; the “Level up” button moves to the parent folder.
- Start scanning with “Generate and scan”. The script collects the file list, then automatically processes files in chunks via AJAX.
- Watch progress: total and processed file counts update until completion.
- When finished:
  - See the total token/word count from the resulting JSONL.
  - Download links appear for JSONL, JSON (single file or split parts), and ZIP (all JSON parts) when available.

## Output format
- **JSONL**: One line per file, with objects like `{"file":"relative/path.php","content":"full file contents"}`.
- **JSON**: A single JSON array with the same objects when size allows; otherwise multiple files named like `project_1.json`, `project_2.json`, etc., respecting `TOKEN_MAX`.
- **ZIP**: ZIP versions are provided for JSONL and JSON for easier downloads; when JSON output is split, all parts are also bundled into a ZIP archive.

## Security notes
- The script exposes project file contents through the browser. Restrict access with Bitrix admin mode or a strong password.
- Do not leave it publicly reachable on production servers without proper protection.
- Review and set `ACCESS_BITRIX`/`ACCESS_PASSWORD` before deploying.

## Limitations and known caveats
- Binary detection relies on a simple null-byte check; some binaries may still slip through or some text files may be skipped if unreadable.
- Very large files or folders can increase processing time despite chunking.
- Token/word counts are approximate because they rely on `str_word_count`.

## Contributing and License
- Contributions, issues, and pull requests are welcome.
- Licensed under the MIT License. See `LICENSE` for details.
