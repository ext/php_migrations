# PHP Migrations

## 2.0.0

### Breaking changes

- `-c` (`--check-only`) has been renamed to `-n` and `--dry-run` to better match
  what other tools call it.

### Features

- Supports `-c` (`--config`) to specify another configuration file.
- `update_database.php` exits with non-zero code if any migration fails.
