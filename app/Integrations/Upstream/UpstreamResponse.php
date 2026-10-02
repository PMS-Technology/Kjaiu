<?php

namespace App\Integrations\Upstream;

/**
 * Value object for a single upstream (or simulated) API exchange.
 *
 * Every SupplierClient method funnels through one of these so the envelope
 * shape never varies: the three keys {status, msg, data} are the same ones the
 * rest of the platform returns, which lets callers treat an upstream failure
 * and a local failure identically.
 */
class UpstreamResponse
{
    public function __construct(
        public readonly bool $status,
        public readonly string $msg = '',
        public readonly mixed $data = null,
        public readonly int $httpStatus = 0,
        public readonly string $method = '',
        public readonly string $path = '',
        public readonly float $duration = 0.0,
        public readonly bool $transportError = false,
    ) {
    }

    /**
     * A successful exchange.
     */
    public static function ok(
        mixed $data = null,
        string $msg = '请求成功',
        int $httpStatus = 200,
        string $method = '',
        string $path = '',
        float $duration = 0.0,
    ): self {
        return new self(
            status: true,
            msg: $msg,
            data: $data,
            httpStatus: $httpStatus,
            method: $method,
            path: $path,
            duration: $duration,
        );
    }

    /**
     * A failure the upstream reported (or that we raised locally).
     */
    public static function fail(
        string $msg = '操作失败',
        mixed $data = null,
        int $httpStatus = 0,
        bool $transportError = false,
        string $method = '',
        string $path = '',
        float $duration = 0.0,
    ): self {
        return new self(
            status: false,
            msg: $msg,
            data: $data,
            httpStatus: $httpStatus,
            method: $method,
            path: $path,
            duration: $duration,
            transportError: $transportError,
        );
    }

    /**
     * The envelope other agents code against.
     *
     * @return array{status:bool,msg:string,data:mixed}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'msg' => $this->msg,
            'data' => $this->data,
        ];
    }

    /**
     * The original protocol signals "nothing to pay" with 1001.
     */
    public function isNotice(): bool
    {
        return (int) $this->httpStatus === 1001;
    }

    public function toException(): UpstreamException
    {
        return new UpstreamException($this->msg, $this, $this->httpStatus);
    }
}
