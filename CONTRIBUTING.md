# Contributing

WPS-Cache requires PHP 8.3 or newer. Production code must stay dependency-free.

Before submitting a change, run:

```bash
php tools/lint.php
php tests/run.php
```

With Composer development dependencies installed, also run:

```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G
```

Keep runtime modules small, inject collaborators through constructors, preserve
drop-in ownership checks, and add a regression test for changed behavior. Build
release candidates with `php tools/build.php`; never assemble release ZIPs by
hand.
