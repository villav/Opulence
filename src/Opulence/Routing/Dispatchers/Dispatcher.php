<?php
/**
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2016 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/master/LICENSE.md
 */
namespace Opulence\Routing\Dispatchers;

use Closure;
use Exception;
use Opulence\Http\HttpException;
use Opulence\Http\Middleware\MiddlewareParameters;
use Opulence\Http\Middleware\ParameterizedMiddleware;
use Opulence\Http\Requests\Request;
use Opulence\Http\Responses\Response;
use Opulence\Pipelines\Pipeline;
use Opulence\Pipelines\PipelineException;
use Opulence\Routing\Controller;
use Opulence\Routing\RouteException;
use Opulence\Routing\Routes\CompiledRoute;
use Opulence\Views\Compilers\ICompiler;
use Opulence\Views\Factories\IViewFactory;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Dispatches routes to the appropriate controllers
 */
class Dispatcher implements IDispatcher
{
    /** @var IDependencyResolver The dependency resolver */
    private $dependencyResolver = null;

    /**
     * @param IDependencyResolver $dependencyResolver The dependency resolver
     */
    public function __construct(IDependencyResolver $dependencyResolver)
    {
        $this->dependencyResolver = $dependencyResolver;
    }

    /**
     * @inheritdoc
     */
    public function dispatch(CompiledRoute $route, Request $request, &$controller = null) : Response
    {
        try {
            $response = (new Pipeline)
                ->send($request)
                ->through($this->convertMiddlewareToPipelineStages($route->getMiddleware()), "handle")
                ->then(function (Request $request) use ($route, &$controller) {
                    if ($route->usesCallable()) {
                        $controller = $route->getController();
                    } else {
                        $controller = $this->createController($route->getControllerName(), $request);
                    }

                    return $this->callController($controller, $route);
                })
                ->execute();

            if ($response === null) {
                // Nothing returned a value, so return a basic HTTP response
                return new Response();
            }

            return $response;
        } catch (PipelineException $ex) {
            throw new RouteException("Failed to dispatch route", 0, $ex);
        }
    }

    /**
     * Calls the method on the input controller
     *
     * @param Controller|Closure|mixed $controller The instance of the controller to call
     * @param CompiledRoute $route The route being dispatched
     * @return Response Returns the value from the controller method
     * @throws RouteException Thrown if the method could not be called on the controller
     * @throws HttpException Thrown if the controller threw an HttpException
     */
    private function callController($controller, CompiledRoute $route) : Response
    {
        try {
            if (is_callable($controller)) {
                $reflection = new ReflectionFunction($controller);
                $parameters = $this->getResolvedControllerParameters(
                    $reflection->getParameters(),
                    $route->getPathVars(),
                    $route,
                    true
                );

                $response = call_user_func_array($controller, $parameters);
            } else {
                $reflection = new ReflectionMethod($controller, $route->getControllerMethod());
                $parameters = $this->getResolvedControllerParameters(
                    $reflection->getParameters(),
                    $route->getPathVars(),
                    $route,
                    false
                );

                if ($reflection->isPrivate()) {
                    throw new RouteException("Method {$route->getControllerMethod()} is private");
                }

                if ($controller instanceof Controller) {
                    $response = call_user_func_array(
                        [$controller, "callMethod"],
                        [$route->getControllerMethod(), $parameters]
                    );
                } else {
                    $response = call_user_func_array([$controller, $route->getControllerMethod()], $parameters);
                }
            }

            if (is_string($response)) {
                $response = new Response($response);
            }

            return $response;
        } catch (HttpException $ex) {
            // We don't want to catch these exceptions, but we want to catch all others
            throw $ex;
        } catch (Exception $ex) {
            throw new RouteException(
                sprintf(
                    "Reflection failed for %s: %s",
                    $route->usesCallable() ? "closure" : "{$route->getControllerName()}::{$route->getControllerMethod()}",
                    $ex
                ),
                0,
                $ex
            );
        }
    }

    /**
     * Converts middleware to pipeline stages
     *
     * @param array $middleware The middleware to convert to pipeline stages
     * @return callable[] The list of pipeline stages
     */
    private function convertMiddlewareToPipelineStages(array $middleware) : array
    {
        $stages = [];

        foreach ($middleware as $singleMiddleware) {
            if ($singleMiddleware instanceof MiddlewareParameters) {
                /** @var MiddlewareParameters $singleMiddleware */
                /** @var ParameterizedMiddleware $tempMiddleware */
                $tempMiddleware = $this->dependencyResolver->resolve($singleMiddleware->getMiddlewareClassName());
                $tempMiddleware->setParameters($singleMiddleware->getParameters());
                $singleMiddleware = $tempMiddleware;
            } elseif (is_string($singleMiddleware)) {
                $singleMiddleware = $this->dependencyResolver->resolve($singleMiddleware);
            }

            $stages[] = $singleMiddleware;
        }

        return $stages;
    }

    /**
     * Creates an instance of the input controller
     *
     * @param string $controllerName The fully-qualified name of the controller class to instantiate
     * @param Request $request The request that's being routed
     * @return Controller|mixed The instantiated controller
     * @throws RouteException Thrown if the controller could not be instantiated
     */
    private function createController(string $controllerName, Request $request)
    {
        if (!class_exists($controllerName)) {
            throw new RouteException("Controller class $controllerName does not exist");
        }

        $controller = $this->dependencyResolver->resolve($controllerName);

        if ($controller instanceof Controller) {
            $controller->setRequest($request);

            try {
                $controller->setViewFactory($this->dependencyResolver->resolve(IViewFactory::class));
            } catch (DependencyResolutionException $ex) {
                // Don't do anything
            }

            try {
                $controller->setViewCompiler($this->dependencyResolver->resolve(ICompiler::class));
            } catch (DependencyResolutionException $ex) {
                // Don't do anything
            }
        }

        return $controller;
    }

    /**
     * Gets the resolved parameters for a controller
     *
     * @param ReflectionParameter[] $reflectionParameters The reflection parameters
     * @param array $pathVars The route path variables
     * @param CompiledRoute $route The route whose parameters we're resolving
     * @param bool $acceptObjectParameters Whether or not we'll accept objects as parameters
     * @return array The mapping of parameter names to their resolved values
     * @throws RouteException Thrown if the parameters could not be resolved
     */
    private function getResolvedControllerParameters(
        array $reflectionParameters,
        array $pathVars,
        CompiledRoute $route,
        bool $acceptObjectParameters
    ) : array
    {
        $resolvedParameters = [];

        // Match the route variables to the method parameters
        foreach ($reflectionParameters as $parameter) {
            if ($acceptObjectParameters && $parameter->getClass() !== null) {
                $className = $parameter->getClass()->getName();
                $resolvedParameters[$parameter->getPosition()] = $this->dependencyResolver->resolve($className);
            } elseif (isset($pathVars[$parameter->getName()])) {
                // There is a value set in the route
                $resolvedParameters[$parameter->getPosition()] = $pathVars[$parameter->getName()];
            } elseif (($defaultValue = $route->getDefaultValue($parameter->getName())) !== null) {
                // There was a default value set in the route
                $resolvedParameters[$parameter->getPosition()] = $defaultValue;
            } elseif (!$parameter->isDefaultValueAvailable()) {
                // There is no value/default value for this variable
                throw new RouteException(
                    "No value set for parameter {$parameter->getName()}"
                );
            }
        }

        return $resolvedParameters;
    }
} 