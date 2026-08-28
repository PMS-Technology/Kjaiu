<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentGateway;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'settings' => SystemSetting::query()->find(1) ?? new SystemSetting([
                'site_name' => config('app.name', 'Kjaiu'),
                'site_url' => config('app.url', ''),
            ]),
            'mail' => $this->mailConfiguration(),
            'gateways' => PaymentGateway::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function updateSite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'site_url' => ['required', 'url:http,https', 'max:2048'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'favicon_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $settings = $this->settings();
        $before = $settings->only(['site_name', 'site_url', 'logo_url', 'favicon_url']);
        $settings->update($data);
        config([
            'app.name' => $settings->site_name,
            'app.url' => $settings->site_url,
            'kjaiu.company_name' => $settings->site_name,
            'kjaiu.site.logo_url' => $settings->logo_url,
            'kjaiu.site.favicon_url' => $settings->favicon_url,
        ]);
        AuditLog::record($request, 'settings.site_updated', $settings, $before, $data);

        return back()->with('success', '站点设置已保存');
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'scheme' => ['required', Rule::in(['smtp', 'smtps', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:4096'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);
        $settings = $this->settings();
        $current = is_array($settings->mail_configuration) ? $settings->mail_configuration : [];
        if (($data['password'] ?? '') === '') {
            $data['password'] = $current['password'] ?? null;
        }
        $data['scheme'] = $data['scheme'] === 'none' ? null : $data['scheme'];
        $settings->update(['mail_configuration' => $data]);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $data['host'],
            'mail.mailers.smtp.port' => $data['port'],
            'mail.mailers.smtp.scheme' => $data['scheme'],
            'mail.mailers.smtp.username' => $data['username'],
            'mail.mailers.smtp.password' => $data['password'],
            'mail.from.address' => $data['from_address'],
            'mail.from.name' => $data['from_name'],
        ]);
        AuditLog::record($request, 'settings.mail_updated', $settings, null, [
            'host' => $data['host'],
            'port' => $data['port'],
            'scheme' => $data['scheme'],
            'username_configured' => filled($data['username']),
            'password_configured' => filled($data['password']),
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'],
        ]);

        return back()->with('success', '邮件配置已保存');
    }

    public function testMail(Request $request): RedirectResponse
    {
        $data = $request->validate(['recipient' => ['required', 'email', 'max:255']]);
        try {
            Mail::raw('这是一封来自 '.config('app.name').' 的邮件配置测试。', function ($message) use ($data): void {
                $message->to($data['recipient'])->subject('邮件配置测试');
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recipient' => '测试邮件发送失败，请检查 SMTP 配置和服务器日志。']);
        }
        AuditLog::record($request, 'settings.mail_tested', null, null, ['recipient' => $data['recipient']]);

        return back()->with('success', '测试邮件已发送');
    }

    public function storeGateway(Request $request): RedirectResponse
    {
        $data = $this->validateGateway($request);
        $gateway = PaymentGateway::create($data);
        AuditLog::record($request, 'payment_gateway.created', $gateway, null, $gateway->only(['name', 'title', 'is_active']));

        return back()->with('success', '收款方式已添加');
    }

    public function updateGateway(Request $request, PaymentGateway $paymentGateway): RedirectResponse
    {
        $before = $paymentGateway->only(['name', 'title', 'is_active', 'sort_order']);
        $request->merge(['name' => $paymentGateway->name]);
        $data = $this->validateGateway($request, $paymentGateway);
        $paymentGateway->update($data);
        AuditLog::record($request, 'payment_gateway.updated', $paymentGateway, $before, $paymentGateway->only(['name', 'title', 'is_active', 'sort_order']));

        return back()->with('success', '收款方式已更新');
    }

    private function validateGateway(Request $request, ?PaymentGateway $gateway = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', Rule::unique('payment_gateways')->ignore($gateway)],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'account' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:-9999', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ]);
        if (in_array(strtolower($data['name']), ['credit', 'cash'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Credit 和 Cash 是系统保留标识，不能用作收款方式。',
            ]);
        }
        $data['is_active'] = $request->boolean('is_active');
        $data['configuration'] = [
            'instructions' => $data['instructions'] ?? '',
            'account' => $data['account'] ?? '',
        ];
        unset($data['instructions'], $data['account']);

        return $data;
    }

    private function settings(): SystemSetting
    {
        return SystemSetting::query()->firstOrCreate(['id' => 1], [
            'site_name' => config('app.name', 'Kjaiu'),
            'site_url' => config('app.url', ''),
        ]);
    }

    private function mailConfiguration(): array
    {
        $stored = SystemSetting::query()->find(1)?->mail_configuration;

        return is_array($stored) ? $stored : [
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'scheme' => config('mail.mailers.smtp.scheme'),
            'username' => config('mail.mailers.smtp.username'),
            'password' => config('mail.mailers.smtp.password'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];
    }
}
