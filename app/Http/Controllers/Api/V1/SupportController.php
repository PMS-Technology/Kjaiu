<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Download;
use App\Models\DownloadCategory;
use App\Models\SystemMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Support content (news, knowledge base, downloads), activity logs and the
 * in-site message centre.
 *
 * The news tables split metadata from body: `shd_news_menu` holds the article
 * (title, publish time, category) while `shd_news` holds only the rendered
 * body keyed by the same id. Knowledge-base articles live entirely in
 * `shd_knowledge_base`.
 */
class SupportController extends ApiController
{
    /** `shd_system_message.type` => the API's type key. */
    protected const MESSAGE_TYPES = [
        1 => 'work_order_message',
        2 => 'product_news',
        3 => 'on_site_news',
        4 => 'event_news',
    ];

    /** Reverse lookup for the `type` query parameter. */
    protected const MESSAGE_TYPE_IDS = [
        'work_order_message' => 1,
        'product_news' => 2,
        'on_site_news' => 3,
        'event_news' => 4,
    ];

    /** Download `type` values, matching the client-area icon set. */
    protected const DOWNLOAD_TYPE_ZIP = 1;
    protected const DOWNLOAD_TYPE_IMAGE = 2;
    protected const DOWNLOAD_TYPE_TEXT = 3;

    /* ---------------------------------------------------------------------
     | News
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/news — categories plus articles.
     */
    public function news(Request $request)
    {
        [$page, $limit] = $this->pagination($request);

        $base = $this->newsQuery($request);

        $total = (clone $base)->count();

        $rows = $base
            ->orderByDesc('push_time')
            ->orderByDesc('id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $categories = $this->newsCategories();
        $list = $rows->map(fn ($row) => $this->articlePayload($row, $categories))->values()->all();

        return $this->ok([
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_page' => (int) ceil($total / max(1, $limit)),
            'cate' => $categories,
            'news' => $list,
            'list' => $list,
        ]);
    }

    /**
     * GET /v1/news/{id} — one article with its neighbours.
     */
    public function newsContent(Request $request, int $id)
    {
        $row = DB::table('news_menu')->where('id', $id)->first();

        if ($row === null || (int) $row->hidden === 1) {
            return $this->fail('文章不存在');
        }

        $body = DB::table('news')->where('relid', $id)->value('content');

        DB::table('news_menu')->where('id', $id)->increment('read');

        $categories = $this->newsCategories();
        $category = collect($categories)->firstWhere('id', (int) $row->parent_id);

        return $this->ok(array_merge($this->articlePayload($row, $categories), [
            'content' => (string) $body,
            'article' => (string) $body,
            'author' => (string) Configuration::value('company_name', ''),
            'cate_name' => (string) ($category['title'] ?? ''),
            'keywords' => (string) $row->keywords,
            'head_img' => (string) $row->head_img,
            'label' => $this->splitLabels((string) ($row->label ?? '')),
            'push_time' => (int) $row->push_time,
            'read' => (int) $row->read + 1,
            'next' => $this->neighbourArticle((int) $row->parent_id, (int) $row->push_time, 'next'),
            'prev' => $this->neighbourArticle((int) $row->parent_id, (int) $row->push_time, 'prev'),
        ]));
    }

    /* ---------------------------------------------------------------------
     | Knowledge base
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/knowledgebase
     */
    public function knowledgebase(Request $request)
    {
        $client = $this->client($request);
        [$page, $limit] = $this->pagination($request);

        $query = DB::table('knowledge_base')
            ->where('hidden', 0);

        // Articles flagged `login_view` are only visible to logged-in clients.
        if ($client === null) {
            $query->where('login_view', 0);
        }

        if ($request->filled('cate_id')) {
            $cateId = (int) $request->input('cate_id');
            $query->whereIn('id', function ($sub) use ($cateId) {
                $sub->select('article_id')->from('knowledge_base_links')->where('category_id', $cateId);
            });
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords) {
                $sub->where('title', 'like', '%' . $keywords . '%')
                    ->orWhere('article', 'like', '%' . $keywords . '%');
            });
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy('order')
            ->orderByDesc('id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $categories = $this->knowledgeCategories();
        $list = $rows->map(fn ($row) => $this->knowledgePayload($row, $categories))->values()->all();

        return $this->ok([
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_page' => (int) ceil($total / max(1, $limit)),
            'cate' => $categories,
            'knowledgebase' => $list,
            'list' => $list,
        ]);
    }

    /**
     * GET /v1/knowledgebase/{id}
     */
    public function knowledgebaseContent(Request $request, int $id)
    {
        $row = DB::table('knowledge_base')->where('id', $id)->first();

        if ($row === null || (int) $row->hidden === 1) {
            return $this->fail('文章不存在');
        }

        if ((int) $row->login_view === 1 && $this->client($request) === null) {
            return $this->fail('请先登录后查看');
        }

        DB::table('knowledge_base')->where('id', $id)->increment('views');

        $categories = $this->knowledgeCategories();
        $link = DB::table('knowledge_base_links')->where('article_id', $id)->first();
        $category = $link === null ? null : collect($categories)->firstWhere('id', (int) $link->category_id);

        $tags = DB::table('knowledge_base_tags')
            ->where('article_id', $id)
            ->pluck('tag')
            ->map(fn ($tag) => (string) $tag)
            ->values()
            ->all();

        return $this->ok(array_merge($this->knowledgePayload($row, $categories), [
            'content' => (string) $row->article,
            'article' => (string) $row->article,
            'author' => (string) $row->public_by,
            'cate_name' => (string) ($category['title'] ?? ''),
            'label' => $tags,
            'read' => (int) $row->views + 1,
            'useful' => (int) $row->useful,
            'next' => $this->neighbourKnowledge((int) $id, 'next'),
            'prev' => $this->neighbourKnowledge((int) $id, 'prev'),
        ]));
    }

    /* ---------------------------------------------------------------------
     | Downloads
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/downloads
     */
    public function downloads(Request $request)
    {
        $client = $this->client($request);

        $query = Download::query()->where('hidden', 0);

        // `clientsonly` rows are withheld from anonymous callers.
        if ($client === null) {
            $query->where('clientsonly', 0);
        }

        if ($request->filled('cate_id')) {
            $query->where('category', (int) $request->input('cate_id'));
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where('title', 'like', '%' . $keywords . '%');
        }

        $categories = DownloadCategory::query()
            ->where('hidden', 0)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (DownloadCategory $category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'description' => (string) $category->description,
                'count' => (int) Download::query()
                    ->where('category', $category->id)
                    ->where('hidden', 0)
                    ->count(),
            ])
            ->values()
            ->all();

        $rows = $query->orderBy('category')->orderBy('id')->get();

        return $this->ok([
            'cate' => $categories,
            'cate_data' => $categories,
            'downloads' => $rows->map(fn (Download $download) => $this->downloadPayload($download))->values()->all(),
            'total' => $rows->count(),
        ]);
    }

    /**
     * GET /v1/downloads/{id} — one resource, counting the hit.
     */
    public function download(Request $request, int $id)
    {
        $client = $this->client($request);
        $download = Download::query()->find($id);

        if ($download === null || (int) $download->hidden === 1) {
            return $this->fail('资源不存在');
        }

        if ((int) $download->clientsonly === 1 && $client === null) {
            return $this->fail('请先登录后下载');
        }

        $download->downloads = (int) $download->downloads + 1;
        $download->save();

        return $this->ok(array_merge($this->downloadPayload($download), [
            'location_url' => $this->signedLocation($download),
        ]), '获取成功');
    }

    /* ---------------------------------------------------------------------
     | Logs
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/log/system
     */
    public function systemLog(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $query = DB::table('system_log')->where('uid', $client->id);

        $this->applyLogFilters($query, $request);

        $paginator = $query
            ->select(['id', 'description', 'ip', 'port', 'create_time', 'user'])
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn ($row) => [
            'id' => (int) $row->id,
            'description' => (string) $row->description,
            'ip' => (string) $row->ip,
            'port' => (int) $row->port,
            'create_time' => (int) $row->create_time,
            'user' => (string) $row->user,
        ]);
    }

    /**
     * GET /v1/log/login — from `shd_activity_log`.
     */
    public function loginLog(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $query = DB::table('activity_log')
            ->where('uid', $client->id);

        // `type = 2` marks a login record in the original schema.
        if (! $request->filled('type')) {
            $query->where('type', 2);
        }

        $this->applyLogFilters($query, $request, 'ipaddr');

        $paginator = $query
            ->select(['id', 'description', 'ipaddr as ip', 'port', 'create_time', 'user'])
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn ($row) => [
            'id' => (int) $row->id,
            'description' => (string) $row->description,
            'ip' => (string) $row->ip,
            'port' => (int) $row->port,
            'create_time' => (int) $row->create_time,
            'user' => (string) $row->user,
        ]);
    }

    /**
     * GET /v1/log/api — from `shd_api_resource_log`.
     */
    public function apiLog(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $query = DB::table('api_resource_log')->where('uid', $client->id);

        $this->applyLogFilters($query, $request, 'ip');

        $paginator = $query
            ->select(['id', 'description', 'ip', 'port', 'create_time', 'source'])
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->paginated($paginator, fn ($row) => [
            'id' => (int) $row->id,
            'description' => (string) $row->description,
            'ip' => (string) $row->ip,
            'port' => (int) $row->port,
            'create_time' => (int) $row->create_time,
            'user' => (string) ($row->source ?: $client->username),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Messages
     | ------------------------------------------------------------------ */

    /**
     * GET /v1/message — message centre.
     */
    public function message(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $type = (string) $request->input('type', '');

        $query = SystemMessage::query()
            ->where('uid', $client->id)
            ->where('delete_time', 0);

        if ($type !== '' && isset(self::MESSAGE_TYPE_IDS[$type])) {
            $query->where('type', self::MESSAGE_TYPE_IDS[$type]);
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords) {
                $sub->where('title', 'like', '%' . $keywords . '%')
                    ->orWhere('content', 'like', '%' . $keywords . '%');
            });
        }

        if ($request->filled('start_time')) {
            $query->where('create_time', '>=', (int) $request->input('start_time'));
        }

        if ($request->filled('end_time')) {
            $query->where('create_time', '<=', (int) $request->input('end_time'));
        }

        $paginator = $query->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);

        $unread = SystemMessage::query()
            ->where('uid', $client->id)
            ->where('delete_time', 0)
            ->where('read_time', 0)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return $this->paginated($paginator, fn (SystemMessage $message) => $this->messagePayload($message), [
            'unread_message' => collect(self::MESSAGE_TYPES)->map(function (string $key, int $id) use ($unread) {
                return [
                    'type' => $key,
                    'count' => (int) ($unread[$id] ?? 0),
                ];
            })->values()->all(),
            'unread_total' => (int) $unread->sum(),
        ]);
    }

    /**
     * PUT /v1/message/{id} — mark one message read.
     */
    public function readMessage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $message = $this->findMessage($client, $id);

        if ($message === null) {
            return $this->fail('消息不存在');
        }

        if ((int) $message->read_time === 0) {
            $message->read_time = time();
            $message->save();
        }

        return $this->ok([
            'id' => (int) $message->id,
            'read_time' => (int) $message->read_time,
        ], '已标记为已读');
    }

    /**
     * DELETE /v1/message/{id} — soft delete via `delete_time`.
     */
    public function deleteMessage(Request $request, int $id)
    {
        $client = $this->requireClient($request);
        $message = $this->findMessage($client, $id);

        if ($message === null) {
            return $this->fail('消息不存在');
        }

        $message->delete_time = time();
        $message->save();

        return $this->ok(['id' => (int) $message->id], '删除成功');
    }

    /* ---------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    protected function findMessage(Client $client, int $id): ?SystemMessage
    {
        return SystemMessage::query()
            ->where('id', $id)
            ->where('uid', $client->id)
            ->where('delete_time', 0)
            ->first();
    }

    protected function messagePayload(SystemMessage $message): array
    {
        $attachments = [];

        if (trim((string) $message->attachment) !== '') {
            $decoded = json_decode((string) $message->attachment, true);

            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $attachments[] = (string) ($item['path'] ?? $item['name'] ?? '');
                    } elseif (is_string($item)) {
                        $attachments[] = $item;
                    }
                }
            } else {
                $attachments[] = (string) $message->attachment;
            }
        }

        return [
            'id' => (int) $message->id,
            'title' => (string) $message->title,
            'content' => (string) $message->content,
            'obj' => (string) $message->obj,
            'attachment' => $attachments,
            'type' => self::MESSAGE_TYPES[(int) $message->type] ?? 'on_site_news',
            'type_id' => (int) $message->type,
            'is_market' => (int) $message->is_market,
            'create_time' => (int) $message->create_time,
            'read_time' => (int) $message->read_time,
        ];
    }

    /**
     * Shared keywords / date-range filtering for the three log endpoints.
     */
    protected function applyLogFilters($query, Request $request, string $ipColumn = 'ipaddr'): void
    {
        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords, $ipColumn) {
                $sub->where('description', 'like', '%' . $keywords . '%')
                    ->orWhere($ipColumn, 'like', '%' . $keywords . '%')
                    ->orWhere('user', 'like', '%' . $keywords . '%');
            });
        }

        if ($request->filled('start_time')) {
            $query->where('create_time', '>=', (int) $request->input('start_time'));
        }

        if ($request->filled('end_time')) {
            $query->where('create_time', '<=', (int) $request->input('end_time'));
        }
    }

    /**
     * News article query with the documented filters.
     */
    protected function newsQuery(Request $request)
    {
        $query = DB::table('news_menu')->where('hidden', 0);

        if ($request->filled('cate_id')) {
            $query->where('parent_id', (int) $request->input('cate_id'));
        }

        $keywords = trim((string) $request->input('keywords', ''));

        if ($keywords !== '') {
            $query->where(function ($sub) use ($keywords) {
                $sub->where('title', 'like', '%' . $keywords . '%')
                    ->orWhere('description', 'like', '%' . $keywords . '%');
            });
        }

        return $query;
    }

    protected function newsCategories(): array
    {
        $type = (int) (DB::table('news_type')->where('title', '新闻公告')->value('id') ?? 0);

        return DB::table('news_type')
            ->where('hidden', 0)
            ->where(function ($sub) use ($type) {
                $sub->where('parent_id', $type)->orWhere('id', $type);
            })
            ->orderBy('sort')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'alias' => (string) $row->alias,
                'count' => (int) DB::table('news_menu')
                    ->where('parent_id', (int) $row->id)
                    ->where('hidden', 0)
                    ->count(),
            ])
            ->values()
            ->all();
    }

    protected function articlePayload(object $row, array $categories): array
    {
        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'keywords' => (string) $row->keywords,
            'description' => (string) $row->description,
            'head_img' => (string) $row->head_img,
            'read' => (int) $row->read,
            'create_time' => (int) $row->create_time,
            'update_time' => (int) $row->update_time,
            'push_time' => (int) $row->push_time,
            'label' => $this->splitLabels((string) ($row->label ?? '')),
            'cate_id' => (int) $row->parent_id,
        ];
    }

    protected function neighbourArticle(int $cateId, int $pushTime, string $direction): ?array
    {
        $query = DB::table('news_menu')
            ->where('hidden', 0)
            ->where('parent_id', $cateId);

        if ($direction === 'next') {
            $row = $query->where('push_time', '>', $pushTime)->orderBy('push_time')->first();
        } else {
            $row = $query->where('push_time', '<', $pushTime)->orderByDesc('push_time')->first();
        }

        return $row === null ? null : [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
        ];
    }

    protected function knowledgeCategories(): array
    {
        $type = (int) (DB::table('news_type')->where('title', '帮助中心')->value('id') ?? 0);

        $categories = DB::table('knowledge_base_cats')
            ->where('hidden', 0)
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty() && $type > 0) {
            // Some installations keep the help-centre categories in
            // `shd_news_type` only.
            $categories = DB::table('news_type')
                ->where('parent_id', $type)
                ->where('hidden', 0)
                ->orderBy('sort')
                ->get();
        }

        return $categories->map(function ($row) {
            $count = DB::table('knowledge_base_links')
                ->where('category_id', (int) $row->id)
                ->count();

            return [
                'id' => (int) $row->id,
                'title' => (string) ($row->title ?? $row->name ?? ''),
                'name' => (string) ($row->name ?? $row->title ?? ''),
                'description' => (string) ($row->description ?? ''),
                'count' => $count,
            ];
        })->values()->all();
    }

    protected function knowledgePayload(object $row, array $categories): array
    {
        $link = DB::table('knowledge_base_links')->where('article_id', (int) $row->id)->first();
        $category = $link === null ? null : collect($categories)->firstWhere('id', (int) $link->category_id);

        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'keywords' => '',
            'description' => '',
            'head_img' => (string) ($row->image ?? ''),
            'read' => (int) $row->views,
            'useful' => (int) $row->useful,
            'create_time' => (int) $row->create_time,
            'update_time' => (int) ($row->update_time ?? 0),
            'push_time' => (int) $row->create_time,
            'label' => [],
            'cate_id' => $link === null ? 0 : (int) $link->category_id,
            'cate_name' => (string) ($category['title'] ?? ''),
        ];
    }

    protected function neighbourKnowledge(int $id, string $direction): ?array
    {
        $query = DB::table('knowledge_base')->where('hidden', 0);

        $row = $direction === 'next'
            ? $query->where('id', '>', $id)->orderBy('id')->first()
            : $query->where('id', '<', $id)->orderByDesc('id')->first();

        return $row === null ? null : [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
        ];
    }

    protected function downloadPayload(Download $download): array
    {
        $category = $download->category;

        return [
            'id' => (int) $download->id,
            'category' => (int) $download->category,
            'category_name' => (string) ($category->name ?? ''),
            'type' => (int) $download->type,
            'title' => (string) $download->title,
            'description' => (string) $download->description,
            'downloads' => (int) $download->downloads,
            'update_time' => (int) $download->update_time,
            'create_time' => (int) $download->create_time,
            'filetype' => (string) $download->filetype,
            'link' => $this->signedLocation($download),
            'down_link' => $this->signedLocation($download),
        ];
    }

    /**
     * Public location of a download file.
     *
     * `location` holds a repository path in the original; a plain URL is
     * passed through so imports from another installation keep working.
     */
    protected function signedLocation(Download $download): string
    {
        $location = trim((string) $download->location);

        if ($location === '') {
            return (string) $download->url;
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        return url($location);
    }

    protected function splitLabels(string $label): array
    {
        $label = trim($label);

        if ($label === '') {
            return [];
        }

        $decoded = json_decode($label, true);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded), fn ($value) => $value !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', $label)), fn ($value) => $value !== ''));
    }
}
