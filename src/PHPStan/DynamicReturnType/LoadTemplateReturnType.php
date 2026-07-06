<?php

declare(strict_types=1);

namespace TwigStan\PHPStan\DynamicReturnType;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Twig\Environment;
use Twig\Template;

final readonly class LoadTemplateReturnType implements DynamicMethodReturnTypeExtension
{
    public function __construct(
        private Environment $twig,
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getClass(): string
    {
        return Template::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'load';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        if (count($methodCall->args) < 2) {
            return null;
        }

        if ( ! $methodCall->args[0] instanceof Arg) {
            return null;
        }

        $templates = $scope->getType($methodCall->args[0]->value)->getConstantStrings();

        if (count($templates) !== 1) {
            return null;
        }

        $template = $templates[0]->getValue();

        $templateClass = $this->twig->getTemplateClass($template);

        // The compiled class only exists when the target template is part of the
        // analysis (e.g. a `{% use %}` of a vendor form theme is not); narrowing
        // to a class PHPStan cannot find would poison every call on the result.
        if ( ! $this->reflectionProvider->hasClass($templateClass)) {
            return null;
        }

        return new ObjectType($templateClass);
    }
}
