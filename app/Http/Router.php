<?php

declare(strict_types=1);

namespace GermanPath\Http;

use RuntimeException;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $pattern = $this->compile($route['pattern']);
            if (preg_match($pattern, $request->path(), $matches) !== 1) {
                continue;
            }

            $parameters = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $parameters[$key] = urldecode($value);
                }
            }

            $response = ($route['handler'])($request, $parameters);
            if (!$response instanceof Response) {
                throw new RuntimeException('Route handlers must return a Response instance.');
            }
            return $response;
        }

        return Response::text('Not Found', 404);
    }

    private function compile(string $pattern): string
    {
        $quoted = preg_quote($pattern, '#');
        $compiled = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static fn (array $match): string => '(?P<' . $match[1] . '>[^/]+)',
            $quoted
        );

        return '#^' . ($compiled ?? $quoted) . '$#';
    }
}
