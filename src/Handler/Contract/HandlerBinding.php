<?php
declare(strict_types=1);

namespace Elephox\Core\Handler\Contract;

use Elephox\Core\Context\Contract\Context;
use Elephox\Core\Handler\InvalidContextException;
use Elephox\Core\Handler\InvalidResultException;

interface HandlerBinding
{
	public function getHandlerMeta(): HandlerMeta;

	/**
	 * @throws InvalidContextException
	 * @throws InvalidResultException
	 */
	public function handle(Context $context): mixed;
}
