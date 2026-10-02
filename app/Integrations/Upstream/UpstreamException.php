<?php

namespace App\Integrations\Upstream;

use RuntimeException;

/**
 * Raised for programmer-visible upstream faults (missing configuration, an
 * unparseable reply). SupplierClient catches these internally and degrades to
 * a failed envelope, because no public method may throw at the caller.
 */
class UpstreamException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?UpstreamResponse $response = null,
        protected int $statusCode = 0,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function response(): ?UpstreamResponse
    {
        return $this->response;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The upstream rejected our JWT and we could not re-authenticate.
     */
    public static function unauthenticated(string $msg = '上游接口鉴权失败'): self
    {
        return new self($msg);
    }

    /**
     * The supplier row is unusable (no hostname, or an unsupported type).
     */
    public static function misconfigured(string $msg = '上游接口配置不完整'): self
    {
        return new self($msg);
    }
}
