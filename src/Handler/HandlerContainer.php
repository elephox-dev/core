<?php
declare(strict_types=1);

namespace Elephox\Core\Handler;

use Closure;
use Elephox\Collection\ArrayList;
use Elephox\Collection\Contract\GenericKeyedEnumerable;
use Elephox\Core\Context\Contract\Context as ContextContract;
use Elephox\Core\Contract\Core as CoreContract;
use Elephox\Core\Handler\Attribute\Contract\HandlerAttribute as HandlerAttributeContract;
use Elephox\Core\Handler\Contract\ComposerAutoloaderInit;
use Elephox\Core\Handler\Contract\ComposerClassLoader;
use Elephox\Core\Middleware\Attribute\Contract\MiddlewareAttribute;
use Elephox\Core\Middleware\Contract\Middleware;
use Elephox\Core\UnhandledContextException;
use Elephox\DI\Contract\Container as ContainerContract;
use Elephox\Files\Contract\Directory as DirectoryContract;
use Elephox\Files\Directory;
use Elephox\OOR\Regex;
use JetBrains\PhpStorm\Pure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

class HandlerContainer implements Contract\HandlerContainer
{
	/**
	 * @return ComposerClassLoader
	 */
	private static function getClassLoader(): object
	{
		/** @var null|class-string<ComposerAutoloaderInit> $autoloaderClassName */
		$autoloaderClassName = null;
		foreach (get_declared_classes() as $class) {
			if (!str_starts_with($class, 'ComposerAutoloaderInit')) {
				continue;
			}

			$autoloaderClassName = $class;

			break;
		}

		if ($autoloaderClassName === null) {
			throw new RuntimeException('Could not find ComposerAutoloaderInit class. Did you install the dependencies using composer?');
		}

		/** @var ComposerClassLoader */
		return call_user_func([$autoloaderClassName, 'getLoader']);
	}

	/**
	 * @var ArrayList<Contract\HandlerBinding>
	 */
	private ArrayList $bindings;

	#[Pure]
	public function __construct(
		private ContainerContract $container,
	)
	{
		/** @var ArrayList<Contract\HandlerBinding> */
		$this->bindings = new ArrayList();
	}

	public function register(Contract\HandlerBinding $binding): void
	{
		$this->bindings[] = $binding;
	}

	public function findHandler(ContextContract $context): Contract\HandlerBinding
	{
		$binding = $this->bindings
			->where(static fn(Contract\HandlerBinding $b): bool => $b->getHandlerMeta()->handles($context))
			->orderByDescending(static fn(Contract\HandlerBinding $b): int => $b->getHandlerMeta()->getWeight())
			->thenBy(static fn(Contract\HandlerBinding $b): float => $b->getHandlerMeta()->getScore($context))
			->first();
		if (empty($binding)) {
			throw new UnhandledContextException($context);
		}

		/** @var Contract\HandlerBinding */
		return $binding;
	}

	/**
	 * @throws ReflectionException
	 */
	public function loadFromClass(string $className): static
	{
		$classInstance = null;

		$classReflection = new ReflectionClass($className);
		$classAttributes = $classReflection->getAttributes(HandlerAttributeContract::class, ReflectionAttribute::IS_INSTANCEOF);
		if (!empty($classAttributes)) {
			if (!method_exists($className, "__invoke")) {
				throw new InvalidClassCallableHandlerException($className);
			}

			$classInstance = $this->container->getOrInstantiate($className);

			/** @noinspection PhpClosureCanBeConvertedToFirstClassCallableInspection */
			$closure = Closure::fromCallable($classInstance);
			$middlewareAttributes = $classReflection->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			$this->registerAttributes($className . '::__invoke', $closure, $classAttributes, $middlewareAttributes);
		}

		$methods = $classReflection->getMethods(ReflectionMethod::IS_PUBLIC);
		foreach ($methods as $methodReflection) {
			$methodAttributes = $methodReflection->getAttributes(HandlerAttributeContract::class, ReflectionAttribute::IS_INSTANCEOF);
			if (empty($methodAttributes)) {
				continue;
			}

			$classInstance ??= $this->container->getOrInstantiate($className);

			/** @var Closure $closure */
			$closure = $methodReflection->getClosure($classInstance);

			$methodMiddlewareAttributes = $methodReflection->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			$classMiddlewareAttributes = $classReflection->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			$middlewareAttributes = [...$methodMiddlewareAttributes, ...$classMiddlewareAttributes];
			$this->registerAttributes($className . "::" . $methodReflection->getName(), $closure, $methodAttributes, $middlewareAttributes);
		}

		if ($classInstance !== null) {
			$core = $this->container->get(CoreContract::class);
			$core->checkRegistrar($classInstance);
		}

		return $this;
	}

	/**
	 * @param non-empty-string $functionName
	 * @param Closure $closure
	 * @param array<array-key, ReflectionAttribute<HandlerAttributeContract>> $handlerAttributes
	 * @param array<array-key, ReflectionAttribute<MiddlewareAttribute>> $middlewareAttributes
	 */
	private function registerAttributes(string $functionName, Closure $closure, array $handlerAttributes, array $middlewareAttributes): void
	{
		foreach ($handlerAttributes as $handlerAttribute) {
			/**
			 * @psalm-suppress UnnecessaryVarAnnotation
			 * @var HandlerAttributeContract $handlerAttributeInstance
			 */
			$handlerAttributeInstance = $handlerAttribute->newInstance();

			/** @var GenericKeyedEnumerable<int, Middleware> $middlewares */
			$middlewares = ArrayList::from($middlewareAttributes)
				->select(static fn(ReflectionAttribute $middlewareAttribute): Middleware => /** @var MiddlewareAttribute */ $middlewareAttribute->newInstance())
				->where(static fn(Middleware $middleware): bool => $middleware->getType()->matchesAny() || $middleware->getType() === $handlerAttributeInstance->getType());

			$binding = new HandlerBinding($functionName, $closure, $handlerAttributeInstance, $middlewares);

			$this->register($binding);
		}
	}

	/**
	 * @throws ReflectionException
	 */
	public function loadFromNamespace(string $namespace): static
	{
		$classLoader = self::getClassLoader();
		foreach ($classLoader->getPrefixesPsr4() as $nsPrefix => $dirs) {
			if (!str_starts_with($namespace, $nsPrefix) && !str_starts_with($nsPrefix, $namespace)) {
				continue;
			}

			/** @var list<string> $parts */
			$parts = Regex::split('/\\\\/', rtrim($namespace, '\\') . '\\')->toList();
			// remove first element since it is the alias for the directories we are iterating
			$root = (string)array_shift($parts);

			foreach ($dirs as $dir) {
				$directory = new Directory($dir);

				$this->loadClassesRecursive($root, $parts, [], $directory, $classLoader);
			}
		}

		return $this;
	}

	/**
	 * @param string $rootNs
	 * @param list<string> $nsParts
	 * @param list<string> $nsPartsUsed
	 * @param DirectoryContract $directory
	 * @param ComposerClassLoader $classLoader
	 * @param int $depth
	 * @throws ReflectionException
	 *
	 * @noinspection PhpDocSignatureInspection
	 */
	private function loadClassesRecursive(string $rootNs, array $nsParts, array $nsPartsUsed, DirectoryContract $directory, object $classLoader, int $depth = 0): void
	{
		if ($depth > 10) {
			throw new RuntimeException("Recursion limit exceeded. Please choose a more specific namespace.");
		}

		$lastPart = (string)array_shift($nsParts);
		$nsPartsUsed[] = $lastPart;
		foreach ($directory->getDirectories() as $dir) {
			if ($dir->getName() !== $lastPart) {

				continue;
			}

			self::loadClassesRecursive($rootNs, $nsParts, $nsPartsUsed, $dir, $classLoader, $depth++);
		}

		if ($lastPart === '') {
			foreach ($directory->getFiles() as $file) {
				$filename = $file->getName();
				if (!str_ends_with($filename, '.php')) {
					continue;
				}

				$className = substr($filename, 0, -4);

				/**
				 * @var class-string $fqcn
				 * @noinspection PhpRedundantVariableDocTypeInspection
				 */
				$fqcn = $rootNs . "\\" . implode("\\", $nsPartsUsed) . $className;

				$classLoader->loadClass($fqcn);

				$this->loadFromClass($fqcn);
			}
		}

		array_unshift($nsParts, array_pop($nsPartsUsed));
	}

	public function getHandlers(): ArrayList
	{
		return $this->bindings;
	}
}
