# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Basic package bootstrap usage | No |
| `extensions.php` | Key scoping, failure classification, payload dot-path keys | No |

Both scripts load `vendor/autoload.php`, so a clean checkout needs dependencies
installed first:

```bash
make install
# or
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
```

Run with Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/extensions.php
```
