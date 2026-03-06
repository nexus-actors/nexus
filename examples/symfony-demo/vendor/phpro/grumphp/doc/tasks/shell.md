# Shell

The Shell task will run your automated shell scripts / commands.
It lives under the `shell` namespace and has following configurable parameters:

```yaml
# grumphp.yml
grumphp:
    tasks:
        shell:
            scripts: []
            ignore_patterns: [],
            whitelist_patterns: [],
            triggered_by: [php]
```

**scripts**

*Default: []*

This options specifies the paths to your shell scripts.
You can specify which executables or shell commands should run.
If you want to run a command, add `-c` as a first argument. This will execute the command instead of trying to open and interpret it.
All scripts / shell commands need to succeed for the task to complete.

Configuration example:

```yaml
# grumphp.yml
grumphp:
    tasks:
        shell:
            scripts:
                - script.sh
                - ["-c", "./bin/command arg1 arg2"]
```

*Note:* When using the `-c` option, the next argument should contain the full executable with all parameters. Be careful: quotes will be escaped!

**ignore_patterns**

*Default: []*

This is a list of file patterns that will be ignored by the shell tasks.
Leave this option blank to run the task for all files defined in the whitelist_patterns and or triggered_by extensions.

**whitelist_patterns**

*Default: []*

This is a list of regex patterns that will filter files to validate. With this option you can skip files like tests.
This option is used in relation with the parameter `triggered_by`.
For example: whitelist files in `src/FolderA/` and `src/FolderB/` you can use
```yaml
whitelist_patterns:
    - /^src\/FolderA\/(.*)/
    - /^src\/FolderB\/(.*)/
```

**triggered_by**

*Default: [php]*

This option will specify which file extensions will trigger the shell tasks.
By default, Shell will be triggered by altering a PHP file.
You can overwrite this option to whatever file you want to use!
