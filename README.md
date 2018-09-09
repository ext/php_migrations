Introduction
============

This is a php-script that helps you handling versions of your database in a
format that works well with code versioning.

This is a fork of [torandi/php_migrations](torandi/php_migrations) with
**breaking** changes.

[torandi/php_migrations]: https://github.com/torandi/php_migrations

Differences
-----------

- `update_database` will exit with error codes on any error.
- `--check` is renamed `--dry-run`.
- Misc additional CLI options such as `--config`.
- Uses `getopt-php` instead of custom argument parsing.

Configuration and setup
-----------------------

1. `composer require sidvind/php-migrations`
2. Create a directory in your project named `migrations` (or whatever)
3. Symlink `update_database.php` and `create_migration.php` into the directory.
4. Copy `config-example.php` to `config.php` and edit it to fit your project
   (see `config-example.php` for more info)

Usage
-----

Use `migrations/create_migration.php migration_name` to create a new migration.

This creates a empty migration with a name like
`20110821231945_migration_name.sql`. The file name (including the date) is the
version name, and must be unique.

You may also specify a second argument to create_migration to select file format
(sql or php):

* SQL: SQL to be run for the migration (multiple lines separated by ;)
* PHP: PHP code to be executed, the environment you loaded in `config.php` is
  available, remember `<?php` and be verbose. Not run in global scope.

To then run the migrations execute `migrations/update_database.php` which runs
all unrun migrations. The table `schema_migrations` are created (if not exist)
containing all run migrations.

### PHP-migration-script-helper-functions

- `migration_sql(query)`: Print and run query
- `run_sql(query)`: Run query in silence

### update_database.php usage

    Usage: migrations/update_database.php [options] [<username>]

    Options:
      -c, --config <arg>  Configuration file to use. Default: "config.php".
      -h, --help          Show this text.
      -n, --dry-run       Only checks if there are migrations to run, won't perform
                          any modifications.

Username may be optional, depending on your config.php
