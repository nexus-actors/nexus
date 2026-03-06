# Composer Validate Autoload

This task checks for PSR-4 or PSR-0 mapping errors. It will run [`composer dump-autoload`](https://getcomposer.org/doc/03-cli.md#dump-autoload-dumpautoload) (with the `--dry-run` option to avoid actually changing files). The configuration looks like:

***Config***

```yaml
# grumphp.yml
grumphp:
    tasks:
        composer_validate_autoload:
            file: ./composer.json
            strict_ambiguous: false
```

**file**

*Default: ./composer.json*

Specifies at which location the `composer.json` file can be found.

**strict_ambiguous**

*Default: false*

Checks whether the same class is ever defined in multiple files. It is set to `false` by default, as enabling it can result in false positives--especially in the common case where polyfill packages are present in the vendor directory.
