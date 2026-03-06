# Clover Coverage

The Clover Coverage task will run your unit tests.

Note that to make sure that there is always a clover file available, you might need to
set `always_execute` to `true` in the `phpunit` task configuration.

It lives under the `clover_coverage` namespace and has following configurable parameters:

```yaml
# grumphp.yml
grumphp:
    tasks:
        clover_coverage:
            clover_file: /tmp/clover.xml
            minimum_level: 100
            target_level: null
```

**clover_file**

*Required*

The location of the clover code coverage XML file.

**minimum_level**

*Default: 100*

The minimum code coverage percentage required to pass.

**target_level**

*Default: null*

Setting a minimum code coverage level is letting the task fail hard.
When you are in the process of increasing your code coverage, you can set a target level.
When the code coverage is below the target level, the task will fail in a non-blocking way.
This gives you the opportunity to increase the code coverage step by step in a non-blocking way whilst keeping track of the progress.
