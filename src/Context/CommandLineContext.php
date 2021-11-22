<?php
declare(strict_types=1);

namespace Elephox\Core\Context;

use Elephox\Core\Handler\ActionType;
use Elephox\DI\Contract\Container;

class CommandLineContext extends AbstractContext implements Contract\CommandLineContext
{
	public function __construct(
		Container $container,
		private ?string $command,
		private array $args,
	)
	{
		parent::__construct(ActionType::Command, $container);

		$container->register(Contract\CommandLineContext::class, $this);
	}

	public function getCommand(): ?string
	{
		return $this->command;
	}

	public function getArgs(): array
	{
		return $this->args;
	}
}
