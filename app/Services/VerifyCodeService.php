<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Email / SMS verification codes.
 *
 * Codes are cached with a scene-scoped key (register, login, forget, bind,
 * second_verify) so a code issued for one flow cannot be replayed in another.
 * Delivery is delegated to whatever mail/SMS transport is configured; when no
 * transport is available the code is logged so local flows stay testable.
 */
class VerifyCodeService
{
    public const TTL = 300;

    /**
     * Issue and remember a code for a scene + account pair.
     */
    public function issue(string $scene, string $account, string $code): void
    {
        Cache::put($this->key($scene, $account), $code, self::TTL);
    }

    /**
     * Check a submitted code and consume it on success.
     */
    public function consume(string $scene, string $account, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $key = $this->key($scene, $account);
        $expected = Cache::get($key);

        if ($expected === null || ! hash_equals((string) $expected, $code)) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    /**
     * Peek at a code without consuming it, used by resend throttling checks.
     */
    public function peek(string $scene, string $account): ?string
    {
        $value = Cache::get($this->key($scene, $account));

        return $value === null ? null : (string) $value;
    }

    /**
     * Deliver a code over the requested channel.
     *
     * Returns false when the channel is unknown so the caller can surface a
     * meaningful error instead of claiming success.
     */
    public function send(string $channel, string $account, string $code, string $scene): bool
    {
        if ($channel === 'email') {
            return $this->sendEmail($account, $code, $scene);
        }

        if ($channel === 'phone') {
            return $this->sendSms($account, $code, $scene);
        }

        return false;
    }

    protected function sendEmail(string $account, string $code, string $scene): bool
    {
        try {
            \Illuminate\Support\Facades\Mail::raw(
                $this->message($code, $scene),
                function ($mail) use ($account) {
                    $mail->to($account)->subject('验证码');
                }
            );

            return true;
        } catch (\Throwable $e) {
            // Without a configured transport the code goes to the log so the
            // flow remains testable; the caller still gets a failure result.
            Log::warning('Verification email could not be sent', [
                'account' => $account,
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function sendSms(string $account, string $code, string $scene): bool
    {
        Log::info('Verification SMS requested', [
            'account' => $account,
            'scene' => $scene,
        ]);

        // SMS gateways are pluggable (aliyun, qcloudsms, smsbao, ...); none is
        // bundled, so delivery reports failure rather than a false success.
        return false;
    }

    protected function message(string $code, string $scene): string
    {
        return '【' . config('kjaiu.name', 'Kjaiu') . '】您的验证码是 ' . $code . '，5分钟内有效。';
    }

    protected function key(string $scene, string $account): string
    {
        return 'verify_code:' . $scene . ':' . $account;
    }
}
