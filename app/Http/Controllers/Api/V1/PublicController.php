<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Configuration;
use App\Models\PaymentGateway;
use App\Services\VerifyCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unauthenticated public endpoints: captcha, verification codes, second
 * verification and the payment gateway list.
 */
class PublicController extends ApiController
{
    public function __construct(
        protected VerifyCodeService $codes = new VerifyCodeService(),
    ) {
    }

    /**
     * GET /v1/captcha — base64 PNG plus the token to submit with it.
     */
    public function captcha(Request $request)
    {
        $idtoken = Str::random(32);
        $code = strtoupper(Str::random(4));

        Cache::put('captcha:' . $idtoken, $code, 300);

        return $this->ok([
            'img' => $this->renderCaptcha($code),
            'idtoken' => $idtoken,
        ]);
    }

    /**
     * POST /v1/code — send an email or SMS verification code.
     *
     * Body: type (register|login|forget|bind), account, action (email|phone).
     */
    public function code(Request $request)
    {
        $scene = (string) $request->input('type', 'register');
        $account = trim((string) $request->input('account', $request->input('email', $request->input('phone', ''))));
        $channel = (string) $request->input('action', filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone');

        if ($account === '') {
            return $this->fail('接收账号不能为空', 406);
        }

        // Graphic captcha is validated first when the setting requires it.
        if (! $this->checkCaptcha($request)) {
            return $this->fail('图形验证码错误', 406);
        }

        $code = (string) random_int(100000, 999999);
        $this->codes->issue($scene, $account, $code);

        $sent = $this->codes->send($channel, $account, $code, $scene);

        if (! $sent) {
            return $this->fail('验证码发送失败，请稍后重试');
        }

        return $this->ok(['expire' => 300], '验证码已发送');
    }

    /**
     * POST /v1/second_verify — submit the 二次验证 code.
     */
    public function secondVerify(Request $request)
    {
        $client = $this->client($request);
        $action = (string) $request->input('action', '');
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            return $this->fail('验证码不能为空', 406);
        }

        if ($client !== null && ! $this->codes->consume('second_verify:' . $action, (string) ($client->email ?: $client->phonenumber), $code)) {
            return $this->fail('验证码错误');
        }

        return $this->ok(null, '验证通过');
    }

    /**
     * GET /v1/gateway — enabled payment methods.
     */
    public function gateway()
    {
        $gateways = PaymentGateway::query()
            ->orderBy('order')
            ->get()
            ->map(fn (PaymentGateway $g) => [
                'name' => (string) $g->gateway,
                'title' => $g->displayName(),
                'img' => (string) ($g->settings()['img'] ?? ''),
            ])
            ->values()
            ->all();

        return $this->ok($gateways);
    }

    /**
     * Validate the graphic captcha when the caller supplied one.
     */
    protected function checkCaptcha(Request $request): bool
    {
        $captcha = (string) $request->input('captcha', '');
        $idtoken = (string) $request->input('idtoken', '');

        if ($captcha === '' && $idtoken === '') {
            return true;
        }

        if ($captcha === '' || $idtoken === '') {
            return false;
        }

        $expected = Cache::pull('captcha:' . $idtoken);

        return $expected !== null && strtoupper($captcha) === strtoupper((string) $expected);
    }

    /**
     * Render a small PNG captcha as a data URL.
     */
    protected function renderCaptcha(string $code): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $width = 120;
        $height = 40;

        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 245, 247, 250);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $palette = [
            imagecolorallocate($image, 40, 60, 90),
            imagecolorallocate($image, 70, 90, 130),
            imagecolorallocate($image, 20, 30, 50),
        ];

        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $palette[array_rand($palette)]
            );
        }

        $x = 14;
        foreach (str_split($code) as $character) {
            imagestring($image, 5, $x, random_int(10, 18), $character, $palette[array_rand($palette)]);
            $x += 24;
        }

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * Default settings surfaced to public pages.
     */
    public function settings(): array
    {
        return [
            'company_name' => (string) Configuration::value('company_name', 'Kjaiu'),
            'allow_register_email' => (int) Configuration::value('allow_register_email', 1),
            'allow_register_phone' => (int) Configuration::value('allow_register_phone', 1),
            'phone_code' => DB::table('sms_country')
                ->orderBy('num_code')
                ->limit(60)
                ->get(['phone_code', 'name_zh'])
                ->map(fn ($row) => [
                    'phone_code' => '+' . (int) $row->phone_code,
                    'link' => (string) $row->name_zh,
                ])
                ->all(),
        ];
    }
}
