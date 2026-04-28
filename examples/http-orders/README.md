# http-orders example

Demonstrates `nexus-http` Phase 1 DSL surface end-to-end via the T3 dev server.

It exercises:

- `path`, `pathPrefix`, `pathEnd`, `concat`
- `get`, `post`, `delete`
- `complete`, `jsonBody`
- `useMiddlewares` (RequestId + Logging)
- `RequestCtx::ask()` against an `OrderActor`

## Run

```
docker compose exec php-swoole php examples/http-orders/bin/serve.php
```

## Try

```
curl -i -X POST -d '{"sku":"X","qty":3}' -H 'Content-Type: application/json' http://localhost:8080/orders
curl -i http://localhost:8080/orders/1
curl -i -X DELETE http://localhost:8080/orders/1
```
