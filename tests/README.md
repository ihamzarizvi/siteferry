# SiteFerry tests

Stand-alone CLI tests; WordPress is not needed for the archive library.

```powershell
# Lint every PHP file and run all suites on LocalWP's PHP 7.4, 8.2 and 8.5 builds.
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1

# Cross-check the TAR format with bsdtar (Windows tar.exe) and GNU tar (Git for Windows).
powershell -ExecutionPolicy Bypass -File tests\interop-tar.ps1
```

Pass `-Keep` to either script to keep the test data in `tests\tmp\` (ignored by git).
A single suite can be run directly, for example `php -n tests\test-tar-roundtrip.php`.

| Suite | Covers |
| --- | --- |
| `test-tar-header.php` | ustar/pax encoding, prefix splitting, sizes over 8 GiB, GNU long names and base-256 numbers, checksums, truncated and corrupt input |
| `test-tar-roundtrip.php` | Pack, list and extract for plain and block-gzip archives; long and Unicode paths, empty files and folders; byte-limited slices with simulated crashes; resumable hashing on PHP 7.4 and 8 |
| `test-tar-changes.php` | Source files that shrink, grow, change or vanish between slices; writer misuse |
| `test-tar-safety.php` | Path validation; refusal of links, devices, FIFOs, sparse files, `..`, absolute paths, drive letters, pax/GNU name tricks, allow-list violations and planted links or junctions |
| `compat-check.php` | Calls to built-in functions newer than PHP 7.4 (syntax is checked by `php -l` on 7.4 itself) |

`interop-tar.ps1` and `run-tests.ps1` call external programs (tar, PHP, a junction for the
safety test); the plugin itself never does.
