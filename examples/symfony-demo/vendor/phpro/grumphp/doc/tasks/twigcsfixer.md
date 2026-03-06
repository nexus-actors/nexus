#  Twig-CS-Fixer

Check and fix Twig coding standard using [VincentLanglet/Twig-CS-Fixer](https://github.com/VincentLanglet/Twig-CS-Fixer).

***Composer***

```
composer require --dev "vincentlanglet/twig-cs-fixer:>=2"
```

***Config***

The task lives under the `twigcsfixer` namespace and has following configurable parameters:

```yaml
# grumphp.yml
grumphp:
    tasks:
        twigcsfixer:
            paths: []
            level: ~
            config: ~
            report: 'text'
            no-cache: false
            verbose: false
            triggered_by: ['twig']
```

**paths**

*Default: []*

By default, current folder will be used.
On precommit only changed files that live in the paths will be passed as arguments.


**level**

*Default: 'notice'*

The level of the messages to display (possibles values are : 'notice', 'warning', 'error').

**config**

*Default: null*

Path to a `.twig-cs-fixer.php` config file. If not set, the default config will be used.

You can check config file [here](https://github.com/VincentLanglet/Twig-CS-Fixer/blob/main/docs/configuration.md).

**report**

*Default: 'text'*

The `--report` option allows to choose the output format for the linter report.

Supported formats are:
- `text` selected by default.
- `checkstyle` following the common checkstyle XML schema.
- `github` if you want annotations on GitHub actions.
- `junit` following JUnit schema XML from Jenkins.
- `null` if you don't want any reporting.

**no-cache**

*Default: false*

Do not use cache.

**verbose**

*Default: false*

Increase the verbosity of messages.

**triggered_by**

*Default: [twig]*

This option will specify which file extensions will trigger this task.
