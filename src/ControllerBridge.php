<?php

declare(strict_types=1);

namespace PhrameCMS\ControllerBridge;

use Closure;
use PhrameCMS\Core\Contracts\ContainerBuilderInterface;
use PhrameCMS\Core\Contracts\ControllerResolverInterface;
use PhrameCMS\Core\Http\Request;
use PhrameCMS\Core\Http\Response;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Throwable;

final class ControllerBridge implements ControllerResolverInterface
{
    private const SYMFONY_REQUEST_CLASS = 'Symfony\\Component\\HttpFoundation\\Request';
    private const SYMFONY_CONTROLLER_RESOLVER_CLASS = 'Symfony\\Component\\HttpKernel\\Controller\\ControllerResolver';

    public static function isAvailable(): bool
    {
        return class_exists(self::SYMFONY_CONTROLLER_RESOLVER_CLASS)
            && class_exists(self::SYMFONY_REQUEST_CLASS);
    }

    public function resolve(string $controllerReference, ContainerBuilderInterface $container): callable
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('Symfony controller component is unavailable.');
        }

        $resolvedCallable = $this->resolveControllerCallable(trim($controllerReference), $container);
        $callableDescription = $this->describeCallable($resolvedCallable);

        return function (Request $request, ContainerBuilderInterface $runtimeContainer) use ($resolvedCallable, $callableDescription): Response {
            $arguments = $this->resolveArguments($resolvedCallable, $request, $runtimeContainer, $callableDescription);

            try {
                $result = $resolvedCallable(...$arguments);
            } catch (Throwable $exception) {
                throw new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }

            if (!$result instanceof Response) {
                throw new RuntimeException(sprintf(
                    'Controller action "%s" must return %s.',
                    $callableDescription,
                    Response::class,
                ));
            }

            return $result;
        };
    }

    private function resolveControllerCallable(string $controllerReference, ContainerBuilderInterface $container): callable
    {
        if ($controllerReference === '') {
            throw new RuntimeException('Controller reference cannot be empty.');
        }

        if (!str_contains($controllerReference, '::')) {
            if (!class_exists($controllerReference)) {
                throw new RuntimeException(sprintf('Controller class "%s" was not found.', $controllerReference));
            }

            if ($container->has($controllerReference)) {
                $service = $container->get($controllerReference);
                if (!is_object($service)) {
                    throw new RuntimeException(sprintf('Controller service "%s" must resolve to an object.', $controllerReference));
                }

                if (!is_callable($service)) {
                    throw new RuntimeException(sprintf('Controller "%s" must be invokable.', $controllerReference));
                }

                return $service;
            }
        }

        if (str_contains($controllerReference, '::')) {
            [$className, $methodName] = explode('::', $controllerReference, 2);
            $className = trim($className);
            $methodName = trim($methodName);

            if ($className === '' || $methodName === '') {
                throw new RuntimeException(sprintf('Invalid controller reference "%s".', $controllerReference));
            }

            if (!class_exists($className)) {
                throw new RuntimeException(sprintf('Controller class "%s" was not found.', $className));
            }

            if ($container->has($className)) {
                $service = $container->get($className);
                if (!is_object($service)) {
                    throw new RuntimeException(sprintf('Controller service "%s" must resolve to an object.', $className));
                }

                if (!method_exists($service, $methodName)) {
                    throw new RuntimeException(sprintf('Controller method "%s::%s" was not found.', $className, $methodName));
                }

                $method = new ReflectionMethod($service, $methodName);
                if (!$method->isPublic()) {
                    throw new RuntimeException(sprintf('Controller method "%s::%s" must be public.', $className, $methodName));
                }

                return [$service, $methodName];
            }
        }

        $requestClass = self::SYMFONY_REQUEST_CLASS;
        $request = new $requestClass();
        $request->attributes->set('_controller', $controllerReference);

        $resolverClass = self::SYMFONY_CONTROLLER_RESOLVER_CLASS;
        $resolver = new $resolverClass();
        $controller = $resolver->getController($request);

        if (!is_callable($controller)) {
            throw new RuntimeException(sprintf('Unable to resolve controller reference "%s".', $controllerReference));
        }

        return $controller;
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveArguments(
        callable $resolvedCallable,
        Request $request,
        ContainerBuilderInterface $container,
        string $callableDescription,
    ): array {
        $reflection = new ReflectionFunction(Closure::fromCallable($resolvedCallable));
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $arguments[] = $this->resolveArgument($parameter, $request, $container, $callableDescription);
        }

        return $arguments;
    }

    private function resolveArgument(
        ReflectionParameter $parameter,
        Request $request,
        ContainerBuilderInterface $container,
        string $callableDescription,
    ): mixed {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            if (is_a($typeName, Request::class, true)) {
                return $request;
            }

            if (is_a($typeName, ContainerBuilderInterface::class, true)) {
                return $container;
            }

            if ($container->has($typeName)) {
                return $container->get($typeName);
            }
        }

        if ($parameter->isOptional()) {
            return $parameter->getDefaultValue();
        }

        throw new RuntimeException(sprintf(
            'Controller argument "$%s" in "%s" could not be resolved.',
            $parameter->getName(),
            $callableDescription,
        ));
    }

    private function describeCallable(callable $callable): string
    {
        if (is_array($callable) && count($callable) === 2) {
            $className = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
            $methodName = is_string($callable[1]) ? $callable[1] : '__invoke';

            return sprintf('%s::%s', $className, $methodName);
        }

        if ($callable instanceof Closure) {
            return 'Closure';
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        return 'callable';
    }
}