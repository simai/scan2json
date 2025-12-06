# scan2json

**scan2json** is a small PHP toolkit for taking a structured “snapshot” of a web project and feeding it to large language models (LLMs) or other analysis tools.

It consists of two scripts:

- `scan.php` – scans a directory under `DOCUMENT_ROOT` and produces JSONL/JSON with file paths and contents.
- `restore.php` – reconstructs a filtered copy of the project from a JSONL snapshot into a separate directory.

The main goal is to give an AI model a consistent, machine-readable view of a codebase: its files, structure, and real source code, without manually copy-pasting snippets in and out of a chat.

---

## Typical use cases

Some common scenarios where scan2json is useful:

- **LLM context for a project**

  Generate a JSONL snapshot of a project and use it as context for:
  - code review,
  - refactoring plans,
  - architecture analysis,
  - “chat with the codebase” style tools.

- **GPT / assistants that “know” a specific project**

  Convert the scan result to JSON and upload it as a file to a GPT assistant so that:
  - the assistant can answer questions about a particular project,
  - developers can ask “where in our project is X implemented?”,
  - you can have different assistants per project.

- **Onboarding and documentation**

  Give newcomers an AI assistant that understands your real code, not just generic PHP/Bitrix examples.

- **Legacy / third-party project audit**

  Quickly get a structured view of a foreign codebase (without giving full repo access) and analyze it via LLMs.

- **Reconstructing a “light” project copy**

  Use `restore.php` to rebuild a filtered project snapshot from JSONL into a clean directory for experiments or sharing with a contractor.

scan2json is not a full backup system. It captures only the files that were included during the scan (with all filters applied), and `restore.php` can only restore those.

---

## Files in this repository

- `scan.php` – main scanner script with a Bootstrap UI.
- `restore.php` – script for restoring files from a JSONL snapshot.
- `LICENSE` – MIT license.
- `README.md` – this file.

---

## Requirements

- PHP **7.4+** (typed functions, strict types, etc.).
- A web server with `DOCUMENT_ROOT` properly set (Apache, Nginx + PHP-FPM, built-in PHP server, etc.).
- Access to the project’s filesystem on the same server where `scan.php` runs.

Bitrix is optional:

- If Bitrix is present and `ACCESS_BITRIX` is enabled, admin authorization can be used to restrict access.
- If Bitrix is not present, the scripts fall back to a password-based login.

---

## Configuration

Both scripts are self-contained. Configuration is done through constants and arrays near the top of `scan.php` and `restore.php`.

Key options in `scan.php`:

- `ACCESS_BITRIX`  
  `true` – require Bitrix admin rights.  
  `false` – use a password prompt.

- `ACCESS_PASSWORD`  
  Password for non-Bitrix mode. **Change the default before exposing the script anywhere.**

- `CHUNK_SIZE`  
  How many files to read per AJAX request while scanning. Used to avoid long-running requests.

- `SKIP_SYMLINKS`  
  Whether to skip symbolic links when walking the filesystem.

- `TOKEN_MAX`  
  Approximate maximum number of words per JSON file. When exceeded, the JSON result is split into parts.

- `$excludePrefixes`  
  Skip files/folders beginning with these prefixes (e.g. `_`, `.git`, `.idea`).

- `$excludeFiles`  
  Specific filenames to ignore (e.g. `composer.lock`).

- `$excludeDirs`  
  Directory names to skip entirely (e.g. `vendor`, `upload`, `log`).

- `DEBUG_MODE`  
  When `true`, extra debug information is collected and can be printed at the bottom of the page.

`restore.php` reuses the same `ACCESS_BITRIX`, `ACCESS_PASSWORD` and `DEBUG_MODE` constants for authentication and logging.

---

## Using `scan.php`

### 1. Deploying the script

1. Copy `scan.php` into a directory under your site’s `DOCUMENT_ROOT`.  
   A common choice is directly in the web root.
2. Optionally create an empty directory `scan_tmp` under `DOCUMENT_ROOT`.  
   If it does not exist, the script will try to create it.
3. Adjust constants at the top of `scan.php`:
   - set access mode (`ACCESS_BITRIX` / `ACCESS_PASSWORD`),
   - customize exclude lists,
   - configure `TOKEN_MAX`, `CHUNK_SIZE`, etc.

Open `https://your-site/scan.php` in a browser to start.

### 2. Authentication

Depending on configuration:

- If `ACCESS_BITRIX` is `true` and Bitrix is available:
  - The script includes Bitrix prolog and checks `$USER->IsAdmin()`.
  - Non-admin users receive HTTP 403.

- If Bitrix is not used:
  - A simple password form is shown.
  - After successful login, a session flag is set.
  - Until the session ends, you can refresh and rescan without re-entering the password.

### 3. Selecting the root folder

You can choose the folder to scan in two ways.

1. **Enter path manually**

   - Radio: **“Enter path manually (" / " is the root)”**.
   - Text field: `scan_folder`.
   - Enter a path relative to `DOCUMENT_ROOT`, for example:
     - `/`
     - `/local`
     - `/bitrix`
     - `/catalog/images`

   This is the quickest way if you already know the path.

2. **Select from list**

   - Radio: **“Select from list”**.
   - The UI shows:
     - current folder (`Current folder: /path`),
     - a button `Go up one level`,
     - a dropdown with subfolders of the current directory.
   - Choosing a subfolder reloads the page and “moves” into it.
   - `Go up one level` moves to the parent directory.
   - `Reset path` (if present) resets the navigation back to the site root.

In both modes the **same selected folder** will be used as the scan root.

### 4. Advanced selection: **Choose items…**

Sometimes you don’t want to scan everything under the root folder.  
For example, you might want to exclude a couple of heavy subdirectories or specific top-level files.

For that, use the **`Choose items…`** button:

1. After you selected a root folder (manually or from the list), click **Choose items…**.
2. A panel appears with:
   - short explanation text;
   - checkboxes for all **immediate subfolders and files** inside the selected root folder.
3. By default, **all items are checked** – scanning behavior is the same as before.
4. Uncheck folders or files you want to exclude from the scan.
5. Click **Apply selection**:
   - the selection is serialized into a hidden field (`custom_selection`);
   - only the checked top-level items will be taken into account during scan.
6. Click **Cancel** if you want to discard changes.

Technical behavior:

- The selection is applied **only at the first level** under the root folder:
  - disallowed folders are never traversed,
  - disallowed files are not added to the file list.
- Once a folder is allowed, its contents are scanned recursively as usual (subject to global exclude rules).

If you never open **Choose items…** or leave everything checked, the scanner behaves exactly as before and processes the whole subtree.

### 5. Running the scan

1. After choosing your folder (and optionally a custom selection), click **Generate and scan**.
2. `scan.php` works in two phases:
   - It first builds a temporary file with a list of paths to scan.
   - Then it automatically starts reading files in **AJAX chunks** of `CHUNK_SIZE` items.
3. The UI shows:
   - the current number of processed files versus total,
   - a progress bar that fills as scanning proceeds.
4. When scanning finishes:
   - status changes to “Done”,
   - a summary with word count appears,
   - download links for all generated files are displayed.

You can interrupt and restart scanning at any time. The script will rebuild the file list and create a new JSON/JSONL set.

### 6. Downloading and using the results

Results are stored under `DOCUMENT_ROOT/scan_tmp` and exposed as download links.

The script can generate:

- **JSONL**

  - One line per file.
  - Each line is a JSON object:

    ```json
    {"file":"relative/path/to/file.php","content":"full file contents"}
    ```

  - This is the most convenient format for:
    - streaming into custom tools,
    - building vector indexes,
    - feeding ML pipelines.

- **JSON**

  - Single JSON array with the same objects:

    ```json
    [
      {"file":"relative/path.php","content":"..."},
      ...
    ]
    ```

  - Useful for tools that expect **one valid JSON document**, such as GPT assistants that cannot accept JSONL files.
  - When the array grows too large (according to `TOKEN_MAX` word limit), it is automatically split into several parts, each containing a subset of records.

- **JSON parts**

  - If splitting was required, the script produces multiple part files (e.g. `project_1.json`, `project_2.json`, …).
  - A ZIP archive with all parts is also produced for convenient download.
  - This is especially useful for uploading to assistants that have file size or token limits per file.

- **ZIP**

  - ZIP versions of JSONL and JSON are created for easier transfer and storage.

Additionally, the UI shows an approximate **token/word count** for the resulting dataset.  
It is based on `str_word_count`, so treat it as an estimate rather than an exact tokenizer count.

### 7. Clearing generated data

The scanner has a button **Delete data** that:

- removes all files created by the scanner under `scan_tmp`,
- resets the internal scan state,
- allows you to start with a clean slate.

This is useful if you work with multiple projects or want to keep the server clean from old snapshots.

---

## Using `restore.php`

`restore.php` performs the reverse operation: it reads a JSONL snapshot and reconstructs the directory structure and files into a target folder under `DOCUMENT_ROOT`.

Important: this is a restore of a **filtered snapshot**, not a full project backup.

### 1. What it restores (and what it does not)

- It creates only the files that are present in the JSONL snapshot.
- Any exclusions that were active during the scan (`excludeDirs`, `excludeFiles`, “Choose items…”) still apply:
  - directories/files that were not scanned will **not** be restored.
- It does not recreate:
  - vendor directories,
  - logs,
  - binary assets,
  - secret configs,
  - or anything else that was excluded in `scan.php`.

Use it for:

- building a lightweight copy of a project for experiments,
- sharing a trimmed version of the project with another developer,
- reconstructing exactly what your LLM saw.

Do not use it as your only backup mechanism.

### 2. Authentication

`restore.php` uses the same auth strategy as `scan.php`:

- If `ACCESS_BITRIX` is `true` and Bitrix is available:
  - only admins (`$USER->IsAdmin()`) are allowed.
- Otherwise:
  - a password login form is shown (`ACCESS_PASSWORD`),
  - after successful login, the session is marked as authenticated.

### 3. Restoring from JSONL: step by step

1. Open `https://your-site/restore.php` in a browser.
2. Authenticate (Bitrix admin or password, depending on configuration).
3. Choose the source JSONL:
   - select a file from the `scan_tmp` dropdown, **or**
   - specify a custom path to a JSONL file (relative or absolute).
4. Choose the target directory:
   - this is a **directory name under `DOCUMENT_ROOT`** (e.g. `restored_project`),
   - the script will create it if necessary,
   - it will reject any path that tries to escape `DOCUMENT_ROOT` (e.g. via `..`).
5. Set options:
   - **Dry run (simulate only)**:
     - no files are written,
     - you get a summary of how many files would be created/overwritten/skipped.
   - **Overwrite existing files**:
     - if checked, existing files in the target directory will be overwritten;
     - if not, they will be counted as “skipped”.
6. Click **Start restore**.
7. The script reads the JSONL file line by line and:
   - validates each record,
   - normalizes paths and checks that they stay within the target directory,
   - creates directories as needed,
   - writes file contents (unless dry run is enabled).

At the end you’ll see a summary:

- total lines processed,
- valid records,
- created files,
- overwritten files,
- skipped files,
- invalid records,
- write errors.

### 4. Path safety

To avoid accidental damage:

- Every record’s `file` is treated as a **relative path** and sanitized:
  - leading slashes are removed,
  - segments with `..` are rejected,
  - backslashes are normalized.
- The script computes an absolute target path and checks that it stays under the chosen base directory using `realpath` and a safe prefix comparison.
- If a path fails validation, the corresponding record is counted as “invalid” and skipped.

---

## Security notes

Both `scan.php` and `restore.php` expose sensitive information (source code) to anyone who can access them.

- Do not place these scripts on a public production server without protection.
- Prefer running them:
  - on a development or staging environment,
  - behind VPN or IP whitelisting,
  - or with strict Bitrix admin checks.
- Always set a strong `ACCESS_PASSWORD` if Bitrix access is disabled.
- Review and adjust exclude lists before scanning:
  - consider excluding `.env`, secret configs, and any other sensitive files.
- Remove the scripts after completing your scans/restores if they are no longer needed.

---

## Limitations

- Binary detection is based on a simple null-byte check; some edge cases may be misclassified.
- Token/word counting is approximate and uses `str_word_count`.
- Very large projects can still take noticeable time, even with chunking.
- `restore.php` cannot recreate files that were excluded from the original scan.

---

## Contributing and license

Contributions, bug reports, and feature ideas are welcome.

- Open an issue or pull request if you want to:
  - add support for other frameworks,
  - improve the UI,
  - add new output formats, etc.
- Licensed under the **MIT License**. See `LICENSE` for details.
