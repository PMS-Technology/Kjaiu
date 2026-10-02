<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Currency;
use App\Models\SystemMessage;
use App\Models\TicketStatus;
use App\Services\PricingService;
use App\Support\ApiResponse;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Shared plumbing for the customer-facing pages.
 *
 * The original client area is a ThinkPHP theme where every template receives a
 * fixed set of globals (`$Userinfo`, `$Setting`, `$Lang`, `$Nav`, `$Currency`,
 * `$Title`, `$TplName`). This base class rebuilds that set so the Blade views
 * — and the JSON endpoints the original JavaScript calls — keep the same shapes.
 */
abstract class WebController extends Controller
{
    /**
     * The signed-in client, or null on public pages.
     */
    protected function client(): ?Client
    {
        $user = auth('client')->user();

        return $user instanceof Client ? $user : null;
    }

    /**
     * The signed-in client, aborting with the platform error when absent.
     */
    protected function requireClient(): Client
    {
        $client = $this->client();

        abort_if($client === null, 401, '请先登录');

        return $client;
    }

    // ---------------------------------------------------------------------
    // Responses
    // ---------------------------------------------------------------------

    protected function ok(mixed $data = null, string $msg = '请求成功', array $extra = []): JsonResponse
    {
        return response()->json(ApiResponse::success($data, $msg, $extra));
    }

    protected function fail(string $msg = '操作失败', int $status = ApiResponse::FAIL, mixed $data = null): JsonResponse
    {
        return response()->json(ApiResponse::error($msg, $status, $data));
    }

    protected function validationFailed(string $msg): JsonResponse
    {
        return $this->fail($msg, ApiResponse::VALIDATION_FAILED);
    }

    protected function unauthorized(string $msg = '请先登录'): JsonResponse
    {
        return $this->fail($msg, ApiResponse::UNAUTHORIZED);
    }

    /**
     * Full-page form posts redirect back with a flash message; AJAX callers get
     * the JSON envelope.
     */
    protected function back(Request $request, bool $success, string $msg, ?string $redirect = null): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $success ? $this->ok(null, $msg) : $this->fail($msg);
        }

        $response = $redirect !== null
            ? redirect($redirect)
            : redirect()->back();

        return $response->with($success ? 'success' : 'error', $msg);
    }

    // ---------------------------------------------------------------------
    // View data
    // ---------------------------------------------------------------------

    /**
     * Values every client-area and storefront view can rely on.
     */
    protected function shared(): array
    {
        $client = $this->client();

        return [
            'Setting' => $this->settingPayload(),
            'Userinfo' => $client === null ? null : $this->userInfoPayload($client),
            'Nav' => $this->navigation($client !== null),
            'Currency' => $this->currencyPayload(),
            'Language' => $this->languages(),
            'CurrencyCheck' => $this->currencyPayload(),
            'TicketStatuses' => $this->ticketStatuses(),
        ];
    }

    /**
     * Render a client-area page with the shared shell data merged in.
     */
    protected function page(string $view, array $data = [], string $title = ''): View
    {
        return view($view, array_merge($this->shared(), [
            'Title' => $title,
            'TplName' => basename(str_replace('.', '/', $view)),
        ], $data));
    }

    /**
     * Render an HTML fragment for the original's `.html(data)` injection.
     */
    protected function fragment(string $view, array $data = []): string
    {
        return view($view, array_merge($this->shared(), $data))->render();
    }

    public function settingPayload(): array
    {
        // The original injects `$Setting.msfntk`, a session-scoped signature the
        // templates post as `mk` when requesting a verification code.
        return array_merge(Settings::site(), [
            'msfntk' => (string) \Illuminate\Support\Facades\Session::get('msfntk', '') ?: $this->issueMkToken(),
        ]);
    }

    /**
     * Lazily create the session captcha/verify signature exposed as `msfntk`.
     */
    protected function issueMkToken(): string
    {
        $token = \Illuminate\Support\Str::random(32);
        \Illuminate\Support\Facades\Session::put('msfntk', $token);

        return $token;
    }

    /**
     * The `$Userinfo` global: account row plus the gates the templates branch on.
     */
    public function userInfoPayload(Client $client): array
    {
        return [
            'user' => [
                'id' => (int) $client->id,
                'username' => (string) ($client->username ?: $client->email ?: $client->phonenumber),
                'email' => (string) $client->email,
                'phonenumber' => (string) $client->phonenumber,
                'phone_code' => (string) $client->phone_code,
                'credit' => PricingService::money((float) $client->credit),
                'api_password' => (string) $client->api_password,
                'api_open' => (int) $client->api_open,
                'second_verify' => (int) $client->second_verify,
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'credit_limit' => PricingService::money((float) $client->credit_limit),
                'credit_limit_balance' => PricingService::money((float) $client->credit_limit_balance),
                'is_login_sms_reminder' => (int) $client->is_login_sms_reminder,
                'email_remind' => (int) $client->email_remind,
                'marketing_emails_opt_in' => (int) $client->marketing_emails_opt_in,
                'send_close' => (int) $client->send_close,
                'companyname' => (string) $client->companyname,
                'qq' => (string) $client->qq,
                'country' => (string) $client->country,
                'province' => (string) $client->province,
                'city' => (string) $client->city,
                'region' => (string) $client->region,
                'address1' => (string) $client->address1,
                'postcode' => (string) $client->postcode,
                'defaultgateway' => (string) $client->defaultgateway,
                'is_password' => trim((string) $client->password) !== '',
                'certifi' => [
                    'status' => 0,
                ],
                'create_time' => (int) $client->create_time,
                'lastlogin' => (int) $client->lastlogin,
                'status' => (int) $client->status,
            ],
            'client_group' => [
                'group_name' => (string) ($client->group?->group_name ?: '默认分组'),
            ],
            'allow_sms_send' => Settings::on('allow_sms_send', true),
            'allow_email_send' => Settings::on('allow_email_send', true),
            'allow_second_verify' => Settings::on('second_verify_home') || Settings::on('second_verify'),
            'allow_resource_api' => Settings::on('allow_resource_api'),
            'customs' => $this->clientCustomFields($client),
            'gateways' => $this->gatewayOptions(),
        ];
    }

    /**
     * Custom client fields with this client's stored values attached.
     */
    protected function clientCustomFields(Client $client): array
    {
        $fields = \App\Models\CustomField::query()
            ->where('type', 'client')
            ->orderBy('sortorder')
            ->get();

        $values = \App\Models\CustomFieldValue::query()
            ->where('relid', $client->id)
            ->pluck('value', 'fieldid');

        return $fields->map(fn ($field) => [
            'id' => (int) $field->id,
            'fieldname' => (string) $field->fieldname,
            'fieldtype' => (string) $field->fieldtype,
            'description' => (string) $field->description,
            'required' => (int) $field->required,
            'sortorder' => (int) $field->sortorder,
            'fieldoptions' => $field->options(),
            'value' => (string) ($values[$field->id] ?? ''),
        ])->all();
    }

    /**
     * Active payment gateways as `name` / `title` pairs.
     */
    protected function gatewayOptions(): array
    {
        // `shd_payment_gateways` is keyed by the gateway name and sorts on a
        // text `order` column, so the ordering is cast to an integer in SQL.
        return \App\Models\PaymentGateway::query()
            ->orderByRaw('CAST(`order` AS UNSIGNED) ASC')
            ->get()
            ->map(fn ($gateway) => [
                'name' => (string) $gateway->gateway,
                'title' => $gateway->displayName(),
                'author_url' => (string) ($gateway->settings()['author_url'] ?? ''),
            ])
            ->all();
    }

    /**
     * Sidebar navigation, rebuilt from `shd_nav`.
     *
     * `menu_type` 1 = client area, 2 = public header. Logged-out visitors get
     * the original's hardcoded fallback menu.
     */
    protected function navigation(bool $loggedIn): array
    {
        $rows = DB::table('nav')
            ->where('menu_type', 1)
            ->orderBy('order')
            ->get();

        if ($rows->isEmpty()) {
            return $loggedIn ? [] : $this->guestNavigation();
        }

        $tree = $this->buildTree($rows);

        return $loggedIn ? $tree : $this->guestNavigation();
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    protected function buildTree(Collection $rows, int $parent = 0): array
    {
        $branch = [];

        foreach ($rows as $row) {
            if ((int) $row->pid !== $parent) {
                continue;
            }

            $children = $this->buildTree($rows, (int) $row->id);

            $branch[] = [
                'id' => (int) $row->id,
                'name' => $this->navLabel($row),
                'url' => (string) $row->url,
                'fa_icon' => (string) $row->fa_icon,
                'hidden_url' => $this->hiddenUrls((string) $row->url),
                'child' => $children,
            ];
        }

        return $branch;
    }

    /**
     * Language label from the `lang` JSON column, falling back to `name`.
     */
    protected function navLabel(object $row): string
    {
        $lang = json_decode((string) $row->lang, true);
        $locale = app()->getLocale() === 'en' ? 'english' : 'chinese';

        if (is_array($lang) && ! empty($lang[$locale])) {
            return (string) $lang[$locale];
        }

        return (string) $row->name;
    }

    /**
     * Child pages that should keep the parent menu entry highlighted; mirrors
     * the `hidden_url` map in the original `app.js`.
     */
    protected function hiddenUrls(string $url): array
    {
        $base = trim(explode('?', $url)[0], '/');

        return match ($base) {
            'billing' => ['viewbilling', 'combinedbilling'],
            'service' => ['servicedetail'],
            'supporttickets' => ['viewticket'],
            'knowledgebase' => ['knowledgebaseview'],
            'news' => ['newsview'],
            'systemlog' => ['loginlog', 'apilog'],
            default => [],
        };
    }

    /**
     * Menu rendered to visitors who are not signed in.
     */
    protected function guestNavigation(): array
    {
        return array_map(fn ($item) => $item + ['hidden_url' => [], 'child' => [], 'fa_icon' => ''], [
            ['id' => -1, 'name' => '首页', 'url' => 'index'],
            ['id' => -2, 'name' => '登录', 'url' => 'login'],
            ['id' => -3, 'name' => '注册', 'url' => 'register'],
            ['id' => -4, 'name' => '订购产品', 'url' => 'cart'],
            ['id' => -5, 'name' => '新闻中心', 'url' => 'news'],
            ['id' => -6, 'name' => '帮助中心', 'url' => 'knowledgebase'],
            ['id' => -7, 'name' => '资源下载', 'url' => 'downloads'],
        ]);
    }

    public function currencyPayload(): array
    {
        $currency = Currency::default();

        return [
            'id' => $currency?->id,
            'code' => (string) ($currency?->code ?? 'CNY'),
            'prefix' => (string) ($currency?->prefix ?? '¥'),
            'suffix' => (string) ($currency?->suffix ?? '元'),
            'format' => (string) ($currency?->format ?? 2),
        ];
    }

    /**
     * Languages offered by the header switcher.
     */
    protected function languages(): array
    {
        return [
            'chinese' => ['display_name' => '简体中文', 'display_flag' => 'CN'],
            'chinese_tw' => ['display_name' => '繁體中文', 'display_flag' => 'TW'],
            'english' => ['display_name' => 'English', 'display_flag' => 'GB'],
        ];
    }

    /**
     * Ticket statuses come from `shd_ticket_status` (administrator editable).
     */
    protected function ticketStatuses(): array
    {
        return TicketStatus::query()
            ->orderBy('order')
            ->get()
            ->mapWithKeys(fn ($status) => [
                (int) $status->id => [
                    'id' => (int) $status->id,
                    'title' => (string) $status->title,
                    'color' => (string) $status->color,
                    'auto_close' => (int) $status->auto_close,
                ],
            ])
            ->all();
    }

    /**
     * Unread in-site messages, used by the topbar bell.
     */
    protected function unreadMessageCount(): int
    {
        $client = $this->client();

        if ($client === null) {
            return 0;
        }

        return SystemMessage::query()
            ->where('uid', $client->id)
            ->where('read_time', 0)
            ->count();
    }

    /**
     * Paging parameters with the documented defaults.
     *
     * @return array{0:int, 1:int}
     */
    protected function pager(Request $request, int $defaultLimit = 20): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = (int) $request->input('limit', $defaultLimit);
        $limit = max(1, min(100, $limit ?: $defaultLimit));

        return [$page, $limit];
    }

    /**
     * Order-by parameters, whitelisted so a query string cannot name an
     * arbitrary column.
     *
     * @param  array<int, string>  $allowed
     */
    protected function orderBy(Request $request, array $allowed, string $default): string
    {
        $column = (string) $request->input('orderby', $default);

        if (! in_array($column, $allowed, true)) {
            $column = $default;
        }

        $direction = strtoupper((string) $request->input('sort', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        return $column . ' ' . $direction;
    }
}
