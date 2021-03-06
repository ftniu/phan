<?php declare(strict_types=1);
namespace Phan\Plugin\Internal;

use Closure;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Type;
use Phan\Language\Type\CallableDeclarationType;
use Phan\Language\Type\CallableType;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeFunctionCallCapability;

/**
 * NOTE: This is automatically loaded by phan. Do not include it in a config.
 *
 * TODO: Analyze returning callables (function() : callable) for any callables that are returned as literals?
 * This would be difficult.
 */
final class CallableParamPlugin extends PluginV2 implements
    AnalyzeFunctionCallCapability
{

    /**
     * @param array<int,int> $params
     * @phan-return Closure(CodeBase,Context,FunctionInterface,array):void
     */
    private static function generateClosure(array $params) : Closure
    {
        $key = \json_encode($params);
        static $cache = [];
        $closure = $cache[$key] ?? null;
        if ($closure !== null) {
            return $closure;
        }
        $closure = function (
            CodeBase $code_base,
            Context $context,
            FunctionInterface $function,
            array $args
        ) use ($params) {
            // TODO: Implement support for variadic callable arguments.
            foreach ($params as $i) {
                $arg = $args[$i] ?? null;
                if (!$arg) {
                    continue;
                }

                // Fetch possible functions. As a side effect, this warns about invalid callables.
                // TODO: Check if the signature allows non-array callables? Not sure of desired semantics.
                $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $arg, true);
                if (\count($function_like_list) === 0) {
                    // Nothing to do
                    continue;
                }

                if (Config::get_track_references()) {
                    foreach ($function_like_list as $function) {
                        $function->addReference($context);
                    }
                }
                // self::analyzeFunctionAndNormalArgumentList($code_base, $context, $function_like_list, $arguments);
            }
        };

        $cache[$key] = $closure;
        return $closure;
    }

    /**
     * @return array<string,\Closure>
     * @phan-return array<string,Closure(CodeBase,Context,FunctionInterface,array):void>
     */
    private function getAnalyzeFunctionCallClosuresStatic(CodeBase $code_base) : array
    {
        $result = [];
        $add_callable_checker_closure = function (FunctionInterface $function) use (&$result) {
            $params = [];
            foreach ($function->getParameterList() as $i => $param) {
                // If there's a type such as Closure|string|int, don't automatically assume that any string or array passed in is meant to be a callable.
                // Explicitly require at least one type to be `callable`
                if ($param->getUnionType()->hasTypeMatchingCallback(function (Type $type) : bool {
                    // TODO: More specific closure for CallableDeclarationType
                    return $type instanceof CallableType || $type instanceof CallableDeclarationType;
                })) {
                    $params[] = $i;
                }
            }
            if (\count($params) === 0) {
                return;
            }
            // Generate a de-duplicated closure.
            // fqsen can be global_function or ClassName::method
            $result[(string)$function->getFQSEN()] = self::generateClosure($params);
        };

        foreach ($code_base->getFunctionMap() as $function) {
            $add_callable_checker_closure($function);
        }
        foreach ($code_base->getMethodSet() as $function) {
            $add_callable_checker_closure($function);
        }

        // new ReflectionFunction('my_func') is a usage of my_func()
        // See https://github.com/phan/phan/issues/1204 for note on function_exists() (not supported right now)
        $result['\\ReflectionFunction::__construct'] = self::generateClosure([0]);

        // When a codebase calls function_exists(string|callable) is to **check** if a function exists,
        // don't emit PhanUndeclaredFunctionInCallable as a side effect.
        unset($result['\\function_exists']);

        // Don't do redundant work extracting function definitions for commonly invoked functions.
        // TODO: Get actual statistics on how frequently used these are
        unset($result['\\call_user_func']);
        unset($result['\\call_user_func_array']);
        unset($result['\\array_map']);
        unset($result['\\array_filter']);
        // End of commonly used functions.

        return $result;
    }

    /**
     * @phan-return array<string,Closure(CodeBase,Context,FunctionInterface,array):void>
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $code_base) : array
    {
        // Unit tests invoke this repeatedly. Cache it.
        static $analyzers = null;
        if ($analyzers === null) {
            $analyzers = self::getAnalyzeFunctionCallClosuresStatic($code_base);
        }
        return $analyzers;
    }
}
