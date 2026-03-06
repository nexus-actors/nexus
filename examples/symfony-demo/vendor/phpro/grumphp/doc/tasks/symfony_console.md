# Symfony Console

Run a symfony console command.

## Requirements

This task requires a Symfony application with console component.

## Config

The task lives under the `symfony_console` namespace and has following configurable parameters:

```yaml
# grumphp.yml
grumphp:
    tasks:
        symfony_console:
            bin: "./bin/console"
            command: [ "lint:container", "-vvv" ]
            ignore_patterns: [],
            whitelist_patterns: [],
            triggered_by: ['php', 'yml', 'xml'],
            run_always: false
```

### Parameters

**bin**

*Default: `./bin/console`*

Specify the path to the Symfony Console script. 

**command**

*Default: `[]`*

Specify the symfony command with defined options and arguments.  
Verify the installed console component version for available commands `./bin/console list`

**ignore_patterns**

*Default: []*

This is a list of file patterns that will be ignored by the Symfony console task. 
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

*Default: [php, yml, xml]*

This option will specify which file extensions will trigger the Symfony console task.
By default, altering a `php`, `yml`, `xml` file will trigger the task.
You can overwrite this option to whatever filetype you want to validate!

**run_always**

*Default: false*

If this is set to `true` the Symfony console task will be executed on every commit, regardless of any modified files.

## Multiple Console command tasks

[Run the same task twice with different configuration](../tasks.md#run-the-same-task-twice-with-different-configuration)

Specific running multiple symfony console commands:

```yaml
# grumphp.yml
grumphp:
  lint-container:
      command: [ "lint:container", "-vvv"]
      metadata:
        task: symfony_console
  lint-yaml:
      command: [ "lint:yaml", "path/to/yaml"]
      metadata:
        task: symfony_console
```
