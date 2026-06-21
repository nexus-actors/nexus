# Syntax error test

<!-- Reason: intentional syntax error fixture — proves verify:skip suppresses php -l failure -->
```php title="broken.php" verify:skip
$x = 1 +;
```
