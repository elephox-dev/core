<?php
declare(strict_types=1);

namespace Elephox\Core\Handler;

use Elephox\Http\Contract as HttpContract;
use Elephox\Http\Contract\Request;
use Elephox\Http\Contract\ServerRequest;
use Elephox\Http\CookieMap;
use Elephox\Http\HeaderMap;
use Elephox\Http\ParameterMap;
use Elephox\Http\RequestMethod;
use Elephox\Http\ServerRequestBuilder;
use Elephox\Http\UploadedFileMap;
use Elephox\Http\Url;
use Elephox\Stream\Contract\Stream;
use Elephox\Stream\EmptyStream;
use JetBrains\PhpStorm\Pure;

class HandledRequestBuilder extends ServerRequestBuilder implements Contract\HandledRequestBuilder
{
	#[Pure]
	public static function fromRequest(Request $request): Contract\HandledRequestBuilder
	{
		return new HandledRequestBuilder(
			$request->getProtocolVersion(),
			$request->getHeaderMap(),
			$request->getBody(),
			$request->getMethod(),
			$request->getUrl(),
			$request instanceof ServerRequest ? $request->getParameters() : null,
			$request instanceof ServerRequest ? $request->getCookieMap() : null,
			$request instanceof ServerRequest ? $request->getUploadedFiles() : null,
			$request instanceof Contract\HandledRequest ? $request->getMatchedTemplate() : null,
		);
	}

	#[Pure]
	public function __construct(
		?string                                $protocolVersion = null,
		?HttpContract\HeaderMap                $headers = null,
		?Stream                                $body = null,
		?RequestMethod                         $method = null,
		?Url                                   $url = null,
		?HttpContract\ParameterMap             $parameters = null,
		?HttpContract\CookieMap                $cookieMap = null,
		?HttpContract\UploadedFileMap          $uploadedFiles = null,
		protected ?Contract\MatchedUrlTemplate $matchedTemplate = null
	) {
		parent::__construct($protocolVersion, $headers, $body, $method, $url, $parameters, $cookieMap, $uploadedFiles);
	}

	public function matchedTemplate(Contract\MatchedUrlTemplate $matchedUrlTemplate): Contract\HandledRequestBuilder
	{
		$this->matchedTemplate = $matchedUrlTemplate;

		return $this;
	}

	public function get(): Contract\HandledRequest
	{
		return new HandledRequest(
			$this->protocolVersion ?? self::DefaultProtocolVersion,
			$this->headers ?? new HeaderMap(),
			$this->body ?? new EmptyStream(),
			$this->method ?? RequestMethod::GET,
			$this->url ?? throw self::missingParameterException("url"),
			$this->parameters ?? new ParameterMap(),
			$this->cookieMap ?? new CookieMap(),
			$this->uploadedFiles ?? new UploadedFileMap(),
			$this->matchedTemplate ?? throw self::missingParameterException("template")
		);
	}
}
