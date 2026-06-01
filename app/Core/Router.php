<?php

declare(strict_types=1);

final class Router
{
    /** @var array<string, array{type:string,target:string,auth?:bool}> */
    private array $routes;

    /**
     * @param array<string, array{type:string,target:string,auth?:bool}> $routes
     */
    public function __construct(array $routes)
    {
        $this->routes = $routes;
    }

    public function resolve(string $path): array
    {
        return $this->routes[$path] ?? $this->routes['__404__'];
    }
}

