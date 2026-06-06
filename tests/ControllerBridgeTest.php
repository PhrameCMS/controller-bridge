<?php

declare(strict_types=1);

namespace PhrameCMS\ControllerBridge\Tests;

use PHPUnit\Framework\TestCase;
use PhrameCMS\ControllerBridge\ControllerBridge;
use PhrameCMS\Core\CoreContainer;
use PhrameCMS\Core\Http\HttpMethod;
use PhrameCMS\Core\Http\Request;
use PhrameCMS\Core\Http\Response;
use RuntimeException;

final class ControllerBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ControllerBridge::isAvailable()) {
            self::markTestSkipped('Symfony HttpKernel controller resolver is unavailable in this environment.');
        }
    }

    public function testInvokableControllerReferenceResolves(): void
    {
        $container = new CoreContainer();
        $resolver = new ControllerBridge();

        $handler = $resolver->resolve(InvokableControllerForBridgeTest::class, $container);
        $response = $handler(new Request(HttpMethod::GET, '/hello', [], [], null), $container);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('invokable', $response->body());
    }

    public function testClassMethodReferenceResolvesWithContainerService(): void
    {
        $container = new CoreContainer();
        $container->set(MethodControllerForBridgeTest::class, static fn (): MethodControllerForBridgeTest => new MethodControllerForBridgeTest());

        $resolver = new ControllerBridge();
        $handler = $resolver->resolve(MethodControllerForBridgeTest::class . '::show', $container);

        $response = $handler(new Request(HttpMethod::GET, '/show', [], [], null), $container);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('method', $response->body());
    }

    public function testWrongControllerReturnTypeThrows(): void
    {
        $container = new CoreContainer();
        $resolver = new ControllerBridge();
        $handler = $resolver->resolve(InvalidReturnControllerForBridgeTest::class, $container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return');

        $handler(new Request(HttpMethod::GET, '/bad', [], [], null), $container);
    }
}

final class InvokableControllerForBridgeTest
{
    public function __invoke(): Response
    {
        return Response::html('<h1>invokable</h1>');
    }
}

final class MethodControllerForBridgeTest
{
    public function show(Request $request): Response
    {
        return Response::html('<h1>method ' . $request->path . '</h1>');
    }
}

final class InvalidReturnControllerForBridgeTest
{
    public function __invoke(): string
    {
        return 'invalid';
    }
}