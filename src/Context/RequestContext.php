<?php
declare(strict_types=1);

namespace Elephox\Core\Context;

use Elephox\Core\Handler\ActionType;
use Elephox\DI\Contract\Container;
use Elephox\Http\Contract\Request;
use JetBrains\PhpStorm\Pure;

class RequestContext extends AbstractContext implements Contract\RequestContext
{
	#[Pure] public function __construct(
		Container $container,
		private Request $request
	)
	{
		parent::__construct(ActionType::Request, $container);
	}

	public function getRequest(): Request
	{
		return $this->request;
	}
}