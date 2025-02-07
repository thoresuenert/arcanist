<?php declare(strict_types=1);

namespace Arcanist\Tests;

use Illuminate\Routing\Router;
use Arcanist\ArcanistServiceProvider;
use PHPUnit\Framework\Assert as PHPUnitAssert;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ArcanistServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        include_once __DIR__ . '/../database/migrations/create_wizards_table.php.stub';
        (new \CreateWizardsTable())->up();
    }

    /**
     * Credits: https://github.com/jasonmccreary/laravel-test-assertions/blob/master/src/Traits/AdditionalAssertions.php
     */
    public function assertRouteUsesMiddleware(string $routeName, array $middlewares, bool $exact = false): void
    {
        $router = resolve(Router::class);

        $router->getRoutes()
            ->refreshNameLookups();

        $route = $router->getRoutes()->getByName($routeName);
        $usedMiddlewares = $route->gatherMiddleware();

        PHPUnitAssert::assertNotNull($route, "Unable to find route for name `$routeName`");

        if ($exact) {
            $unusedMiddlewares = array_diff($middlewares, $usedMiddlewares);
            $extraMiddlewares = array_diff($usedMiddlewares, $middlewares);

            $messages = [];

            if ($extraMiddlewares) {
                $messages[] = 'uses unexpected `' . implode(', ', $extraMiddlewares) . '` middlware(s)';
            }

            if ($unusedMiddlewares) {
                $messages[] = "doesn't use expected `" . implode(', ', $unusedMiddlewares) . '` middlware(s)';
            }

            $messages = implode(' and ', $messages);

            PHPUnitAssert::assertSame(count($unusedMiddlewares) + count($extraMiddlewares), 0, "Route `$routeName` " . $messages);
        } else {
            $unusedMiddlewares = array_diff($middlewares, $usedMiddlewares);

            PHPUnitAssert::assertSame(count($unusedMiddlewares), 0, "Route `$routeName` does not use expected `" . implode(', ', $unusedMiddlewares) . '` middleware(s)');
        }
    }
}
