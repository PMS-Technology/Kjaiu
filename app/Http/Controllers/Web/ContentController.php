<?php

namespace App\Http\Controllers\Web;

use App\Models\Download;
use App\Models\DownloadCategory;
use App\Support\Settings;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Content pages: 新闻中心 (/news), 帮助中心 (/knowledgebase),
 * 资源下载 (/downloads) and 推介计划 (/affiliates).
 */
class ContentController extends WebController
{
    // -----------------------------------------------------------------
    // News
    // -----------------------------------------------------------------

    public function news(Request $request): View
    {
        [$page, $limit] = $this->pager($request, 10);
        $cate = (int) $request->input('cate', 0);
        $keywords = trim((string) $request->input('keywords', ''));

        $query = DB::table('news_menu')
            ->where('hidden', '0')
            ->when($cate > 0, fn ($q) => $q->where('parent_id', $cate))
            ->when($keywords !== '', fn ($q) => $q->where('title', 'like', '%' . $keywords . '%'));

        $total = (clone $query)->count();

        $rows = $query->orderByRaw($this->orderBy($request, ['id', 'push_time'], 'id'))
            ->forPage($page, $limit)
            ->get(['id', 'title', 'description', 'keywords', 'head_img', 'push_time', 'parent_id']);

        return view('web.content.news', array_merge($this->shared(), [
            'Title' => '新闻中心',
            'TplName' => 'news',
            'NewsList' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'head_img' => (string) $row->head_img,
                'push_time' => (int) $row->push_time,
                'cate_id' => (int) $row->parent_id,
            ])->all(),
            'classify' => $this->newsCategories(),
            'cate' => $cate,
            'keywords' => $keywords,
            'Total' => $total,
            'Limit' => $limit,
            'Page' => $page,
            'Pages' => (int) max(1, ceil($total / $limit)),
        ]));
    }

    public function newsView(Request $request): View|RedirectResponse
    {
        $id = (int) $request->input('id', 0);
        $article = DB::table('news_menu')->where('hidden', '0')->find($id);

        if ($article === null) {
            return redirect()->to('/news')->with('error', '文章不存在');
        }

        $content = (string) (DB::table('news')->where('relid', $id)->value('content') ?? '');
        $category = DB::table('news_menu')->find((int) $article->parent_id);

        return view('web.content.newsview', array_merge($this->shared(), [
            'Title' => (string) $article->title,
            'TplName' => 'newsview',
            'ViewAnnouncement' => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'cate_name' => (string) ($category->title ?? ''),
                'push_time' => (int) $article->push_time,
                'create_time' => (int) $article->create_time,
                'content' => $content,
                'prev' => $this->siblingNews((int) $article->id, 'prev'),
                'next' => $this->siblingNews((int) $article->id, 'next'),
            ],
        ]));
    }

    /**
     * GET news/list, news/notice and friends.
     */
    public function newsList(Request $request, string $kind = 'list'): JsonResponse
    {
        [$page, $limit] = $this->pager($request, 10);
        $cate = (int) $request->input('cate', 0);

        $query = DB::table('news_menu')
            ->where('hidden', '0')
            ->when($cate > 0, fn ($q) => $q->where('parent_id', $cate));

        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get(['id', 'title', 'description', 'push_time']);

        return $this->ok([
            'list' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'push_time' => (int) $row->push_time,
            ])->all(),
            'total' => (clone $query)->count(),
            'page' => $page,
            'limit' => $limit,
            'kind' => $kind,
        ]);
    }

    /**
     * GET news/notice — the announcement-only feed.
     */
    public function noticeList(Request $request): JsonResponse
    {
        return $this->newsList($request, 'notice');
    }

    /**
     * GET news/content
     */
    public function newsContent(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('news_menu')->find($id);

        if ($row === null) {
            return $this->fail('文章不存在');
        }

        return $this->ok([
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'content' => (string) (DB::table('news')->where('relid', $id)->value('content') ?? ''),
            'push_time' => (int) $row->push_time,
        ]);
    }

    /**
     * GET news/catelist
     */
    public function newsCates(): JsonResponse
    {
        return $this->ok($this->newsCategories());
    }

    // -----------------------------------------------------------------
    // Knowledge base
    // -----------------------------------------------------------------

    public function knowledgebase(Request $request): View
    {
        [$page, $limit] = $this->pager($request, 10);
        $cate = (int) $request->input('cate', 0);
        $keywords = trim((string) $request->input('keywords', ''));

        $articleIds = [];

        if ($cate > 0) {
            $articleIds = DB::table('knowledge_base_links')
                ->where('category_id', $cate)
                ->pluck('article_id')
                ->all();
        }

        $query = DB::table('knowledge_base')
            ->where('hidden', 0)
            ->when($cate > 0, fn ($q) => $q->whereIn('id', $articleIds ?: [0]))
            ->when($keywords !== '', function ($q) use ($keywords) {
                $q->where(function ($sub) use ($keywords) {
                    $sub->where('title', 'like', '%' . $keywords . '%')
                        ->orWhere('article', 'like', '%' . $keywords . '%');
                });
            });

        $total = (clone $query)->count();

        $rows = $query->orderByRaw($this->orderBy($request, ['id', 'create_time', 'views'], 'id'))
            ->forPage($page, $limit)
            ->get(['id', 'title', 'views', 'useful', 'create_time', 'public_by']);

        return view('web.content.knowledgebase', array_merge($this->shared(), [
            'Title' => '帮助中心',
            'TplName' => 'knowledgebase',
            'help' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'views' => (int) $row->views,
                'useful' => (int) $row->useful,
                'create_time' => (int) $row->create_time,
                'public_by' => (string) $row->public_by,
            ])->all(),
            'classify' => $this->knowledgeCategories(),
            'cate' => $cate,
            'keywords' => $keywords,
            'Total' => $total,
            'Limit' => $limit,
            'Page' => $page,
            'Pages' => (int) max(1, ceil($total / $limit)),
        ]));
    }

    public function knowledgebaseView(Request $request): View|RedirectResponse
    {
        $id = (int) $request->input('id', 0);
        $article = DB::table('knowledge_base')->where('hidden', 0)->find($id);

        if ($article === null) {
            return redirect()->to('/knowledgebase')->with('error', '文章不存在');
        }

        // A login-gated article is invisible to visitors.
        if ((int) $article->login_view === 1 && $this->client() === null) {
            return redirect()->to('/login')->with('error', '请先登录后查看该文章');
        }

        DB::table('knowledge_base')->where('id', $id)->increment('views');

        $labels = DB::table('knowledge_base_tags')->where('article_id', $id)->pluck('tag')->all();
        $categoryIds = DB::table('knowledge_base_links')->where('article_id', $id)->pluck('category_id')->all();
        $category = $categoryIds === [] ? null : DB::table('knowledge_base_cats')->find($categoryIds[0]);

        return view('web.content.knowledgebaseview', array_merge($this->shared(), [
            'Title' => (string) $article->title,
            'TplName' => 'knowledgebaseview',
            'KnowledgeBaseArticle' => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'cate_name' => (string) ($category->name ?? ''),
                'create_time' => (int) $article->create_time,
                'public_by' => (string) $article->public_by,
                'description' => (string) $article->image,
                'content' => (string) $article->article,
                'views' => (int) $article->views,
                'useful' => (int) $article->useful,
                'labels' => $labels,
                'prev' => $this->siblingArticle($id, 'prev'),
                'next' => $this->siblingArticle($id, 'next'),
            ],
        ]));
    }

    /**
     * POST knowledge_base/search_article
     */
    public function searchArticle(Request $request): JsonResponse
    {
        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords === '') {
            return $this->fail('请输入搜索关键词');
        }

        $rows = DB::table('knowledge_base')
            ->where('hidden', 0)
            ->where(function ($query) use ($keywords) {
                $query->where('title', 'like', '%' . $keywords . '%')
                    ->orWhere('article', 'like', '%' . $keywords . '%');
            })
            ->orderByDesc('views')
            ->limit(20)
            ->get(['id', 'title', 'views']);

        return $this->ok($rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'views' => (int) $row->views,
        ])->all());
    }

    /**
     * POST knowledge_base/tags_list
     */
    public function tagsList(Request $request): JsonResponse
    {
        $tags = DB::table('knowledge_base_tags')
            ->selectRaw('tag, count(*) as aggregate')
            ->groupBy('tag')
            ->orderByDesc('aggregate')
            ->limit(50)
            ->get();

        return $this->ok($tags->map(fn ($row) => [
            'tag' => (string) $row->tag,
            'count' => (int) $row->aggregate,
        ])->all());
    }

    /**
     * GET knowledge_base/view_article/<id>
     */
    public function viewArticle(int $id): JsonResponse
    {
        $row = DB::table('knowledge_base')->where('hidden', 0)->find($id);

        if ($row === null) {
            return $this->fail('文章不存在');
        }

        if ((int) $row->login_view === 1 && $this->client() === null) {
            return $this->unauthorized('请先登录后查看该文章');
        }

        return $this->ok([
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'content' => (string) $row->article,
            'create_time' => (int) $row->create_time,
        ]);
    }

    // -----------------------------------------------------------------
    // Downloads
    // -----------------------------------------------------------------

    public function downloads(Request $request): View
    {
        [$page, $limit] = $this->pager($request, 20);
        $cateId = (int) $request->input('cate_id', 0);
        $keywords = trim((string) $request->input('keywords', ''));

        $query = Download::query()
            ->where('hidden', 0)
            ->when($cateId > 0, fn ($q) => $q->where('category', $cateId))
            ->when($keywords !== '', fn ($q) => $q->where('title', 'like', '%' . $keywords . '%'));

        $total = (clone $query)->count();

        $rows = $query->orderByRaw($this->orderBy($request, ['id', 'create_time', 'downloads'], 'id'))
            ->forPage($page, $limit)
            ->get();

        return view('web.content.downloads', array_merge($this->shared(), [
            'Title' => '资源下载',
            'TplName' => 'downloads',
            'Downloads' => [
                'location_url' => '',
                'downloads' => [
                    'cate_data' => $this->downloadCategories(),
                    'downloads' => $rows->map(fn (Download $row) => $this->downloadRow($row))->all(),
                    'cate_id' => $cateId,
                    'keywords' => $keywords,
                    'Total' => $total,
                    'Limit' => $limit,
                    'Page' => $page,
                    'Pages' => (int) max(1, ceil($total / $limit)),
                ],
            ],
        ]));
    }

    /**
     * GET download/cates
     */
    public function downloadCates(): JsonResponse
    {
        return $this->ok($this->downloadCategories());
    }

    /**
     * POST download/search
     */
    public function downloadSearch(Request $request): JsonResponse
    {
        $keywords = trim((string) $request->input('keywords', ''));

        $rows = Download::query()
            ->where('hidden', 0)
            ->when($keywords !== '', fn ($q) => $q->where('title', 'like', '%' . $keywords . '%'))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->ok($rows->map(fn (Download $row) => $this->downloadRow($row))->all());
    }

    /**
     * GET download/product_file — downloads attached to one product.
     */
    public function productFile(Request $request): JsonResponse
    {
        $productId = (int) $request->input('pid', $request->input('product_id', 0));

        $ids = DB::table('product_downloads')->where('product_id', $productId)->pluck('download_id')->all();

        if ($ids === []) {
            return $this->ok([]);
        }

        $rows = Download::query()->whereIn('id', $ids)->where('hidden', 0)->get();

        return $this->ok($rows->map(fn (Download $row) => $this->downloadRow($row))->all());
    }

    // -----------------------------------------------------------------
    // Affiliates
    // -----------------------------------------------------------------

    public function affiliates(Request $request): View|RedirectResponse
    {
        $client = $this->requireClient();
        $action = (string) $request->input('action', '');

        if (! Settings::on('affiliate_enabled')) {
            return redirect()->to('/clientarea')->with('error', '推介计划未开启');
        }

        $affiliate = DB::table('affiliates')->where('uid', $client->id)->first();

        // Nothing activated yet: the unaffiliates panel offers activation.
        if ($affiliate === null) {
            return view('web.affiliates.index', array_merge($this->shared(), [
                'Title' => '推介计划',
                'TplName' => 'affiliates',
                'Affiliates' => [
                    'is_open' => 1,
                    'aff' => 0,
                    'data' => [],
                    'affiliate_withdraw' => Settings::float('affiliate_withdraw', 0),
                ],
            ]));
        }

        $payload = [
            'is_open' => 1,
            'aff' => 1,
            'data' => [
                'balance' => number_format((float) $affiliate->balance, 2, '.', ''),
                'withdraw_ing' => number_format((float) $affiliate->withdraw_ing, 2, '.', ''),
                'audited_balance' => number_format((float) $affiliate->audited_balance, 2, '.', ''),
                'payamount' => (int) $affiliate->payamount,
                'visitors' => (int) $affiliate->visitors,
                'registcount' => (int) $affiliate->registcount,
                'url' => url('/?aff=' . (string) ($affiliate->url_identy ?: $client->id)),
                'suffix' => '',
            ],
            'affiliate_withdraw' => Settings::float('affiliate_withdraw', 0),
        ];

        // The tab fragments the original loads with `?action=`.
        $payload['buy_records'] = in_array($action, ['affbuyrecord', 'affpage'], true)
            ? $this->affiliateBuyRecords($client->id)
            : [];
        $payload['withdraw_records'] = in_array($action, ['withdrawrecord', 'affpage'], true)
            ? $this->withdrawRecords($client->id)
            : [];
        $payload['user_list'] = in_array($action, ['useraffilist', 'affpage'], true)
            ? $this->affiliateUsers($client->id)
            : [];

        return view('web.affiliates.index', array_merge($this->shared(), [
            'Title' => '推介计划',
            'TplName' => 'affiliates',
            'Affiliates' => $payload,
            'action' => $action,
        ]));
    }

    /**
     * GET /activation — join the affiliate programme.
     */
    public function activation(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        if (! Settings::on('affiliate_enabled')) {
            return $this->fail('推介计划未开启');
        }

        if (DB::table('affiliates')->where('uid', $client->id)->exists()) {
            return $this->fail('已开通推介计划');
        }

        DB::table('affiliates')->insert([
            'uid' => $client->id,
            'visitors' => 0,
            'registcount' => 0,
            'payamount' => 0,
            'balance' => 0,
            'withdraw_ing' => 0,
            'audited_balance' => 0,
            'url_identy' => (string) $client->id,
            'created_time' => time(),
            'updated_time' => time(),
        ]);

        return $this->ok(['aff' => 1], '开通成功');
    }

    /**
     * POST /withdraw — request an affiliate payout.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        if (! Settings::on('affiliate_enabled')) {
            return $this->fail('推介计划未开启');
        }

        $affiliate = DB::table('affiliates')->where('uid', $client->id)->first();

        if ($affiliate === null) {
            return $this->fail('请先开通推介计划');
        }

        $amount = round((float) $request->input('num', $request->input('amount', 0)), 2);
        $minimum = Settings::float('affiliate_withdraw', 0);

        if ($amount <= 0) {
            return $this->fail('请输入提现金额');
        }

        if ($minimum > 0 && $amount < $minimum) {
            return $this->fail('最低提现金额为 ' . number_format($minimum, 2, '.', ''));
        }

        if ($amount > (float) $affiliate->balance) {
            return $this->fail('可提现余额不足');
        }

        $methodId = (int) $request->input('account_id', $request->input('method_id', 0));

        DB::transaction(function () use ($client, $amount, $methodId) {
            DB::table('affiliates_withdraw')->insert([
                'uid' => $client->id,
                'num' => $amount,
                'type' => 1,
                'admin_id' => 0,
                'account_id' => $methodId,
                'status' => 1,
                'reason' => '',
                'create_time' => time(),
                'update_time' => time(),
            ]);

            DB::table('affiliates')->where('uid', $client->id)->update([
                'balance' => DB::raw('balance - ' . $amount),
                'withdraw_ing' => DB::raw('withdraw_ing + ' . $amount),
                'updated_time' => time(),
            ]);
        });

        return $this->ok(null, '提现申请已提交');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function newsCategories(): array
    {
        return DB::table('news_menu')
            ->where('hidden', '0')
            ->where('parent_id', 0)
            ->orderBy('sort')
            ->get(['id', 'title'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'count' => DB::table('news_menu')->where('parent_id', $row->id)->where('hidden', '0')->count(),
            ])
            ->all();
    }

    protected function siblingNews(int $id, string $direction): array
    {
        $query = DB::table('news_menu')->where('hidden', '0');

        $row = $direction === 'prev'
            ? $query->where('id', '<', $id)->orderByDesc('id')->first(['id', 'title'])
            : $query->where('id', '>', $id)->orderBy('id')->first(['id', 'title']);

        return $row === null ? [] : ['id' => (int) $row->id, 'title' => (string) $row->title];
    }

    protected function knowledgeCategories(): array
    {
        return DB::table('knowledge_base_cats')
            ->where('hidden', 0)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->name,
                'count' => DB::table('knowledge_base_links')->where('category_id', $row->id)->count(),
            ])
            ->all();
    }

    protected function siblingArticle(int $id, string $direction): array
    {
        $query = DB::table('knowledge_base')->where('hidden', 0);

        $row = $direction === 'prev'
            ? $query->where('id', '<', $id)->orderByDesc('id')->first(['id', 'title'])
            : $query->where('id', '>', $id)->orderBy('id')->first(['id', 'title']);

        return $row === null ? [] : ['id' => (int) $row->id, 'title' => (string) $row->title];
    }

    protected function downloadCategories(): array
    {
        return DownloadCategory::query()
            ->where('hidden', 0)
            ->orderBy('sort')
            ->get()
            ->map(fn (DownloadCategory $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'file_count' => (int) Download::query()->where('category', $row->id)->where('hidden', 0)->count(),
            ])
            ->all();
    }

    protected function downloadRow(Download $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'description' => (string) $row->description,
            'down_link' => (string) ($row->url ?: $row->location),
            'update_time' => (int) $row->update_time ?: (int) $row->create_time,
            'downloads' => (int) $row->downloads,
            'type' => $this->downloadType((string) $row->type),
        ];
    }

    /**
     * `shd_downloads.type` may hold a number or a word; both map to the three
     * icon classes the template uses.
     */
    protected function downloadType(string $type): int
    {
        if (is_numeric($type)) {
            return (int) $type;
        }

        return match (strtolower($type)) {
            'zip', 'rar', 'gz', '7z' => 1,
            'png', 'jpg', 'jpeg', 'gif', 'image' => 2,
            default => 3,
        };
    }

    protected function affiliateBuyRecords(int $clientId): array
    {
        $rows = DB::table('invoices')
            ->where('is_aff', 1)
            ->where('is_delete', 0)
            ->whereIn('uid', function ($query) use ($clientId) {
                $query->select('id')->from('affiliates_user')->where('affid', $clientId);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'create_time' => (int) $row->create_time,
            'subtotal' => number_format((float) $row->subtotal, 2, '.', ''),
            'type' => (string) $row->type,
            'is_aff' => (int) $row->is_aff === 1 ? '已确认' : '待确认',
            'aff_sure_time' => (int) $row->aff_sure_time,
            'paid_time' => (int) $row->paid_time,
            'commission' => number_format((float) $row->aff_commission, 2, '.', ''),
        ])->all();
    }

    protected function withdrawRecords(int $clientId): array
    {
        return DB::table('affiliates_withdraw')
            ->where('uid', $clientId)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'create_time' => (int) $row->create_time,
                'num' => number_format((float) $row->num, 2, '.', ''),
                'type' => StatusMap::WITHDRAW_TYPE[(int) $row->type] ?? '',
                'status' => StatusMap::WITHDRAW_STATUS[(int) $row->status] ?? '',
                'reason' => (string) $row->reason,
                'user_nickname' => '',
            ])
            ->all();
    }

    protected function affiliateUsers(int $clientId): array
    {
        return DB::table('affiliates_user')
            ->join('clients', 'clients.id', '=', 'affiliates_user.uid')
            ->where('affiliates_user.affid', $clientId)
            ->orderByDesc('affiliates_user.id')
            ->get(['clients.username', 'clients.email', 'clients.phonenumber', 'clients.create_time', 'clients.lastlogin'])
            ->map(fn ($row) => [
                'username' => (string) $row->username,
                'email' => (string) $row->email,
                'phonenumber' => (string) $row->phonenumber,
                'create_time' => (int) $row->create_time,
                'lastlogin' => (int) $row->lastlogin,
            ])
            ->all();
    }
}
