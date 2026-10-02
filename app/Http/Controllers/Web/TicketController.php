<?php

namespace App\Http\Controllers\Web;

use App\Models\Host;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\TicketStatus;
use App\Support\ApiResponse;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Support tickets: list, submit, view, reply, close, rate.
 *
 * Statuses are rows in `shd_ticket_status` rather than an enum, so the badge
 * title and colour always come from the database (the original only hardcodes
 * the "closed" check against status id 4).
 */
class TicketController extends WebController
{
    /** Attachment suffixes the original accepts. */
    protected const ALLOWED_SUFFIXES = ['jpg', 'jpeg', 'gif', 'png'];

    // -----------------------------------------------------------------
    // List
    // -----------------------------------------------------------------

    public function index(Request $request): View
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $tickets = Ticket::query()
            ->where('uid', $client->id)
            ->when($request->filled('status_id'), fn ($query) => $query->where('status', (int) $request->input('status_id')))
            ->when(trim((string) $request->input('keywords', '')) !== '', function ($query) use ($request) {
                $keywords = '%' . trim((string) $request->input('keywords')) . '%';
                $query->where(function ($sub) use ($keywords) {
                    $sub->where('title', 'like', $keywords)->orWhere('tid', 'like', $keywords);
                });
            })
            ->orderByRaw($this->orderBy($request, ['id', 'create_time', 'last_reply_time'], 'id'))
            ->paginate($limit, ['*'], 'page', $page);

        return view('web.ticket.index', array_merge($this->shared(), [
            'Title' => '工单列表',
            'TplName' => 'supporttickets',
            'tickets' => $this->ticketRows($tickets->items()),
            'Total' => $tickets->total(),
            'Limit' => $tickets->perPage(),
            'Page' => $tickets->currentPage(),
            'Pages' => $tickets->lastPage(),
        ]));
    }

    /**
     * GET ticket/list
     */
    public function listJson(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        [$page, $limit] = $this->pager($request, 20);

        $tickets = Ticket::query()
            ->where('uid', $client->id)
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return $this->ok([
            'list' => $this->ticketRows($tickets->items()),
            'total' => $tickets->total(),
            'page' => $tickets->currentPage(),
            'limit' => $tickets->perPage(),
            'total_page' => $tickets->lastPage(),
        ]);
    }

    // -----------------------------------------------------------------
    // Submit
    // -----------------------------------------------------------------

    /**
     * GET|POST /submitticket — step 1 picks a department, step 2 the details.
     */
    public function submit(Request $request): View|RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $step = (string) $request->input('step', '1');

        if ($request->isMethod('post')) {
            return $this->create($request, $client);
        }

        $departments = $this->departments($client);

        if ($step === '2') {
            $departmentId = (int) $request->input('dptid', 0);
            $department = collect($departments)->firstWhere('id', $departmentId);

            if ($department === null) {
                return redirect()->to('/submitticket')->with('error', '请选择有效的工单部门');
            }

            return view('web.ticket.submit', array_merge($this->shared(), [
                'Title' => '提交工单',
                'TplName' => 'submitticket',
                'step' => 2,
                'SubmitTicket' => [
                    'department' => $departments,
                    'ticketpage' => [
                        'host_list' => $this->hostOptions($client),
                        'priority' => $this->priorities(),
                    ],
                    'department_selected' => $departmentId,
                ],
                'ticketCustom' => $this->ticketCustomFields(),
                'preselected_pid' => (int) $request->input('pid', 0),
            ]));
        }

        return view('web.ticket.submit_step1', array_merge($this->shared(), [
            'Title' => '提交工单',
            'TplName' => 'submitticket',
            'step' => 1,
            'SubmitTicket' => ['department' => $departments],
        ]));
    }

    /**
     * POST ticket/create — the JSON submit endpoint.
     */
    public function createJson(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $result = $this->create($request, $client, true);

        return $result instanceof JsonResponse ? $result : $this->ok(null, '提交成功');
    }

    /**
     * Create the ticket and its opening message.
     */
    protected function create(Request $request, $client, bool $json = false): RedirectResponse|JsonResponse
    {
        $departmentId = (int) $request->input('dptid', 0);
        $title = trim((string) $request->input('title', ''));
        $content = trim((string) $request->input('content', ''));

        $department = TicketDepartment::query()->find($departmentId);

        if ($department === null || (int) $department->hidden === 1) {
            return $this->submitFailure($request, '请选择有效的工单部门', $json);
        }

        if ($title === '') {
            return $this->submitFailure($request, '请输入工单标题', $json);
        }

        if ($content === '') {
            return $this->submitFailure($request, '请输入工单内容', $json);
        }

        $attachments = $this->storeAttachments($request, 'tickets');

        if ($attachments === null) {
            return $this->submitFailure($request, '附件格式不支持，仅允许 ' . implode('、', self::ALLOWED_SUFFIXES), $json);
        }

        $hostId = (int) $request->input('hostid', 0);

        // A ticket may only reference a service the client actually owns.
        if ($hostId > 0 && ! Host::query()->where('uid', $client->id)->where('id', $hostId)->exists()) {
            $hostId = 0;
        }

        $ticket = DB::transaction(function () use ($request, $client, $departmentId, $title, $content, $hostId, $attachments) {
            $ticket = Ticket::create([
                'tid' => $this->nextTicketNumber(),
                'dptid' => $departmentId,
                'uid' => $client->id,
                'host_id' => $hostId,
                'name' => (string) ($client->username ?: $client->email),
                'email' => (string) $client->email,
                'create_time' => time(),
                'title' => mb_substr($title, 0, 255),
                'content' => $content,
                'status' => $this->defaultStatusId(),
                'priority' => strtolower((string) $request->input('priority', 'medium')),
                'attachment' => json_encode($attachments, JSON_UNESCAPED_UNICODE),
                'last_reply_time' => time(),
                'client_unread' => 0,
                'admin_unread' => 1,
                // `shd_ticket.c` is varchar(20), so the tracking token is sized
                // to fit rather than truncated by the database.
                'c' => Str::random(20),
                'update_time' => time(),
            ]);

            $this->storeTicketCustomFields($ticket, $request);

            return $ticket;
        });

        $url = '/viewticket?tid=' . $ticket->tid . '&c=' . $ticket->c;

        if ($json || $request->expectsJson() || $request->ajax()) {
            return $this->ok([
                'tid' => (string) $ticket->tid,
                'id' => (int) $ticket->id,
                'url' => $url,
            ], '提交成功');
        }

        return redirect()->to($url)->with('success', '提交成功');
    }

    /**
     * GET ticket/department
     */
    public function departmentList(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        return $this->ok(['list' => $this->departments($client)]);
    }

    /**
     * GET ticket/ticket_page — departments, services and priorities.
     */
    public function ticketPage(Request $request): JsonResponse
    {
        $client = $this->requireClient();

        return $this->ok([
            'department' => $this->departments($client),
            'host_list' => $this->hostOptions($client),
            'priority' => $this->priorities(),
        ]);
    }

    /**
     * GET ticket/get_custom — custom fields for one department.
     */
    public function getCustom(Request $request): JsonResponse
    {
        $this->requireClient();
        $fields = $this->ticketCustomFields((int) $request->input('dptid', 0));

        return $this->ok($fields);
    }

    // -----------------------------------------------------------------
    // View / reply / close / rate
    // -----------------------------------------------------------------

    public function view(Request $request): View|RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $ticket = $this->findTicket($request, $client->id);

        if ($ticket === null) {
            return redirect()->to('/supporttickets')->with('error', '工单不存在');
        }

        if ($request->isMethod('post')) {
            return $this->reply($request, $ticket);
        }

        // Opening the ticket clears its client-side unread marker.
        if ((int) $ticket->client_unread > 0) {
            $ticket->client_unread = 0;
            $ticket->save();
        }

        $status = TicketStatus::query()->find($ticket->status);

        return view('web.ticket.view', array_merge($this->shared(), [
            'Title' => '工单详情',
            'TplName' => 'viewticket',
            'ViewTicket' => [
                'ticket' => [
                    'id' => (int) $ticket->id,
                    'tid' => (string) $ticket->tid,
                    'title' => (string) $ticket->title,
                    'status' => [
                        'id' => (int) $ticket->status,
                        'title' => (string) ($status?->title ?: '未知'),
                        'color' => (string) ($status?->color ?: '#888888'),
                    ],
                    'create_time' => (int) $ticket->create_time,
                    'last_reply_time' => (int) $ticket->last_reply_time,
                    'department' => ['name' => (string) ($ticket->department?->name ?: '')],
                    'host' => (string) ($ticket->host?->domain ?: ''),
                    'host_id' => (int) $ticket->host_id,
                    'priority' => ucfirst((string) $ticket->priority),
                    'c' => (string) $ticket->c,
                    'star' => (int) $ticket->star,
                ],
                'list' => $this->replyRows($ticket),
                'feedback_request' => (int) ($ticket->department?->feedback_request ?? 0) === 1,
                'custom_fields' => $this->ticketCustomFields((int) $ticket->dptid, $ticket),
            ],
        ]));
    }

    /**
     * POST ticket/reply — add a customer reply.
     */
    public function reply(Request $request, ?Ticket $ticket = null): RedirectResponse|JsonResponse
    {
        $client = $this->requireClient();
        $ticket = $ticket ?? $this->findTicket($request, $client->id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        if ($this->isClosed($ticket)) {
            return $this->fail('工单已关闭，无法回复');
        }

        $content = trim((string) $request->input('content', ''));

        if ($content === '') {
            return $this->fail('请输入回复内容');
        }

        $attachments = $this->storeAttachments($request, 'tickets');

        if ($attachments === null) {
            return $this->fail('附件格式不支持，仅允许 ' . implode('、', self::ALLOWED_SUFFIXES));
        }

        TicketReply::create([
            'tid' => $ticket->id,
            'uid' => $client->id,
            'create_time' => time(),
            'content' => $content,
            'admin' => '',
            'admin_id' => 0,
            'attachment' => json_encode($attachments, JSON_UNESCAPED_UNICODE),
            'star' => 0,
            'editor' => 'plain',
            'source' => 0,
        ]);

        // A customer reply moves the ticket to the "customer replied" status
        // when one is configured, otherwise it goes back to 待处理.
        $ticket->status = $this->awaitingStatusId();
        $ticket->last_reply_time = time();
        $ticket->admin_unread = 1;
        $ticket->save();

        $target = '/viewticket?tid=' . $ticket->tid . '&c=' . $ticket->c;

        if ($request->expectsJson() || $request->ajax()) {
            return $this->ok(['url' => $target], '回复成功');
        }

        return redirect()->to($target)->with('success', '回复成功');
    }

    /**
     * POST /ticket/close
     */
    public function close(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $ticket = $this->findTicket($request, $client->id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        $closedId = $this->closedStatusId();

        if ($closedId === null) {
            return $this->fail('系统未配置关闭状态');
        }

        $ticket->status = $closedId;
        $ticket->last_reply_time = time();
        $ticket->save();

        return $this->ok(['url' => '/supporttickets'], '工单已关闭');
    }

    /**
     * POST /ticket/evaluate — rate a staff reply.
     */
    public function evaluate(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $ticket = $this->findTicket($request, $client->id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        $star = max(1, min(5, (int) $request->input('star', 5)));
        $replyId = (int) $request->input('rid', 0);

        $reply = TicketReply::query()
            ->where('tid', $ticket->id)
            ->when($replyId > 0, fn ($query) => $query->where('id', $replyId))
            ->orderByDesc('id')
            ->first();

        if ($reply === null) {
            return $this->fail('回复不存在');
        }

        if ((int) $reply->star > 0) {
            return $this->fail('该回复已评价');
        }

        $reply->star = $star;
        $reply->save();

        $ticket->star = $star;
        $ticket->save();

        return $this->ok(['star' => $star], '评价成功');
    }

    /**
     * GET ticket/detail
     */
    public function detail(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $ticket = $this->findTicket($request, $client->id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        return $this->ok([
            'ticket' => [
                'id' => (int) $ticket->id,
                'tid' => (string) $ticket->tid,
                'title' => (string) $ticket->title,
                'status' => (int) $ticket->status,
                'create_time' => (int) $ticket->create_time,
            ],
            'list' => $this->replyRows($ticket),
        ]);
    }

    /**
     * GET ticket/download — attachment download by stored path.
     */
    public function download(Request $request)
    {
        $client = $this->requireClient();
        $path = (string) $request->input('path', '');

        // Only attachments under the upload root are served, and only for
        // tickets the client owns.
        $safe = $this->safeAttachmentPath($path, $client->id);

        if ($safe === null) {
            abort(404);
        }

        return response()->download($safe);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function findTicket(Request $request, int $clientId): ?Ticket
    {
        $id = (int) $request->input('id', 0);
        $tid = (string) $request->input('tid', '');

        $query = Ticket::query()->where('uid', $clientId);

        if ($id > 0) {
            return $query->find($id);
        }

        if ($tid === '') {
            return null;
        }

        return $query->where('tid', $tid)->first();
    }

    /**
     * @param  array<int, Ticket>  $tickets
     */
    protected function ticketRows(array $tickets): array
    {
        if ($tickets === []) {
            return [];
        }

        $departments = TicketDepartment::query()
            ->whereIn('id', array_filter(array_map(fn (Ticket $t) => $t->dptid, $tickets)))
            ->get()
            ->keyBy('id');

        $statuses = $this->ticketStatuses();

        return array_map(fn (Ticket $ticket) => [
            'id' => (int) $ticket->id,
            'tid' => (string) $ticket->tid,
            'title' => (string) $ticket->title,
            'c' => (string) $ticket->c,
            'department_name' => (string) ($departments[$ticket->dptid]->name ?? ''),
            'create_time' => (int) $ticket->create_time,
            'last_reply_time' => (int) $ticket->last_reply_time,
            'priority' => ucfirst((string) $ticket->priority),
            'status' => $statuses[(int) $ticket->status] ?? [
                'id' => (int) $ticket->status,
                'title' => '未知',
                'color' => '#888888',
            ],
            'client_unread' => (int) $ticket->client_unread,
        ], $tickets);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function replyRows(Ticket $ticket): array
    {
        $replies = TicketReply::query()
            ->where('tid', $ticket->id)
            ->orderBy('create_time')
            ->orderBy('id')
            ->get();

        $rows = [[
            'id' => 0,
            'admin' => 0,
            'user_type' => 'client',
            'realname' => (string) ($ticket->name ?: '我'),
            'format_time' => (int) $ticket->create_time,
            'content' => (string) $ticket->content,
            'attachment' => $this->attachmentLinks($ticket->attachmentList()),
            'star' => 0,
            'type' => 'create',
        ]];

        foreach ($replies as $reply) {
            $rows[] = [
                'id' => (int) $reply->id,
                'admin' => $reply->isStaffReply() ? 1 : 0,
                'user_type' => $reply->isStaffReply() ? 'admin' : 'client',
                'realname' => $reply->isStaffReply()
                    ? (string) ($reply->admin ?: '客服')
                    : (string) ($ticket->name ?: '我'),
                'format_time' => (int) $reply->create_time,
                'content' => (string) $reply->content,
                'attachment' => $this->attachmentLinks($reply->attachmentList()),
                'star' => (int) $reply->star,
                'type' => 'reply',
            ];
        }

        return $rows;
    }

    /**
     * Attachment entries stored as "path^name".
     *
     * @param  array<int, string>  $attachments
     */
    protected function attachmentLinks(array $attachments): array
    {
        $links = [];

        foreach ($attachments as $attachment) {
            $attachment = (string) $attachment;
            $separator = strpos($attachment, '^');
            $path = $separator === false ? $attachment : substr($attachment, 0, $separator);
            $name = $separator === false ? basename($attachment) : substr($attachment, $separator + 1);

            $links[] = ['path' => $path, 'name' => $name];
        }

        return $links;
    }

    /**
     * Departments a client may open tickets in.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function departments($client): array
    {
        return TicketDepartment::query()
            ->where('hidden', 0)
            ->where(function ($query) use ($client) {
                // `only_reg_client` departments are hidden from guests, and the
                // original also allows restricting them to product owners.
                $query->where('only_reg_client', 0)->orWhere('only_reg_client', 1);
            })
            ->orderBy('order')
            ->get()
            ->map(fn (TicketDepartment $department) => [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
                'description' => (string) $department->description,
                'only_reg_client' => (int) $department->only_reg_client,
                'feedback_request' => (int) $department->feedback_request,
            ])
            ->all();
    }

    /**
     * Services offered in the "related product" select.
     *
     * @return array<int|string, string>
     */
    protected function hostOptions($client): array
    {
        $hosts = Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->orderByDesc('id')
            ->get();

        $products = \App\Models\Product::query()
            ->whereIn('id', $hosts->pluck('productid')->all())
            ->get()
            ->keyBy('id');

        $options = [0 => '不关联产品'];

        foreach ($hosts as $host) {
            $name = $products[$host->productid]->name ?? '产品';

            $options[$host->id] = sprintf('%s - %s (#%d)', $name, $host->domain ?: $host->id, $host->id);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function priorities(): array
    {
        return ['low' => '低', 'medium' => '中', 'high' => '高'];
    }

    /**
     * Custom fields configured for ticket departments.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ticketCustomFields(int $departmentId = 0, ?Ticket $ticket = null): array
    {
        $rows = \App\Models\CustomField::query()
            ->where('type', 'ticket')
            ->when($departmentId > 0, fn ($query) => $query->where('relid', $departmentId))
            ->orderBy('sortorder')
            ->get();

        $values = [];

        if ($ticket !== null) {
            $values = DB::table('customfieldsvalues')
                ->where('relid', $ticket->id)
                ->pluck('value', 'fieldid')
                ->all();
        }

        return $rows->map(fn ($field) => [
            'id' => (int) $field->id,
            'fieldname' => (string) $field->fieldname,
            'fieldtype' => (string) $field->fieldtype,
            'description' => (string) $field->description,
            'required' => (int) $field->required,
            'dropdown_option' => array_map(
                fn ($options) => ['option_name' => $options],
                $field->options()
            ),
            'value' => (string) ($values[$field->id] ?? ''),
        ])->all();
    }

    /**
     * Persist `customfield[<id>]` values submitted with a ticket.
     */
    protected function storeTicketCustomFields(Ticket $ticket, Request $request): void
    {
        foreach ((array) $request->input('customfield', []) as $fieldId => $value) {
            $field = \App\Models\CustomField::query()->find((int) $fieldId);

            if ($field === null) {
                continue;
            }

            if ($field->required === 1 && (string) $value === '') {
                continue;
            }

            DB::table('customfieldsvalues')->updateOrInsert(
                ['fieldid' => (int) $fieldId, 'relid' => $ticket->id],
                [
                    'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                    'create_time' => time(),
                    'update_time' => time(),
                ]
            );
        }
    }

    /**
     * Store uploaded attachments under `storage/app/public/tickets/<uid>`.
     *
     * Returns null when a file has a disallowed suffix, so the caller can
     * reject the whole submission.
     *
     * @return array<int, string>|null entries shaped "path^name"
     */
    protected function storeAttachments(Request $request, string $folder): ?array
    {
        $files = $request->file('attachments');

        if ($files === null) {
            return [];
        }

        $files = is_array($files) ? $files : [$files];
        $stored = [];

        foreach ($files as $file) {
            if ($file === null || ! $file->isValid()) {
                continue;
            }

            $suffix = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($suffix, self::ALLOWED_SUFFIXES, true)) {
                return null;
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                return null;
            }

            $name = $file->getClientOriginalName();
            $path = $file->store($folder, 'public');

            $stored[] = $path . '^' . $name;
        }

        return $stored;
    }

    /**
     * POST /uploads and /upload_image — store a form attachment and return its
     * path, which the rich-text editors embed.
     */
    public function upload(Request $request): JsonResponse
    {
        $this->requireClient();

        $file = $request->file('file') ?? $request->file('image');

        if ($file === null || ! $file->isValid()) {
            return $this->fail('请选择要上传的文件');
        }

        $suffix = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($suffix, self::ALLOWED_SUFFIXES, true)) {
            return $this->fail('文件格式不支持，仅允许 ' . implode('、', self::ALLOWED_SUFFIXES), 406);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->fail('文件大小不能超过 5MB', 406);
        }

        $path = $file->store('uploads', 'public');

        return $this->ok([
            'path' => $path,
            'url' => '/storage/' . $path,
            'name' => $file->getClientOriginalName(),
        ], '上传成功');
    }

    /**
     * Resolve a stored attachment path, refusing anything outside the upload
     * tree or belonging to another client's ticket.
     */
    protected function safeAttachmentPath(string $path, int $clientId): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $owned = TicketReply::query()
            ->join('ticket', 'ticket.id', '=', 'ticket_reply.tid')
            ->where('ticket.uid', $clientId)
            ->where('ticket_reply.attachment', 'like', '%' . $path . '%')
            ->exists();

        $ownedTicket = Ticket::query()
            ->where('uid', $clientId)
            ->where('attachment', 'like', '%' . $path . '%')
            ->exists();

        if (! $owned && ! $ownedTicket) {
            return null;
        }

        $full = storage_path('app/public/' . $path);

        return is_file($full) ? $full : null;
    }

    /**
     * Status a new or answered-by-customer ticket takes.
     */
    protected function defaultStatusId(): int
    {
        $status = TicketStatus::query()->orderBy('order')->first();

        return (int) ($status?->id ?? 1);
    }

    /**
     * Status used after a customer reply: the one flagged `show_await`.
     */
    protected function awaitingStatusId(): int
    {
        $status = TicketStatus::query()->where('show_await', 1)->orderBy('order')->first();

        return (int) ($status?->id ?? $this->defaultStatusId());
    }

    /**
     * Id of the closed status (the original checks `status.id != 4`); the row
     * with `auto_close` set and the highest order is the closed one.
     */
    protected function closedStatusId(): ?int
    {
        $status = TicketStatus::query()
            ->where('auto_close', 1)
            ->orderByDesc('order')
            ->first();

        return $status === null ? null : (int) $status->id;
    }

    protected function isClosed(Ticket $ticket): bool
    {
        return $this->closedStatusId() === (int) $ticket->status;
    }

    /**
     * Sequential ticket number, mirroring the original's "YYYYMMDDnn" shape.
     */
    protected function nextTicketNumber(): string
    {
        $prefix = date('Ymd');
        $sequence = Ticket::query()->where('tid', 'like', $prefix . '%')->count() + 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function submitFailure(Request $request, string $msg, bool $json = false): RedirectResponse|JsonResponse
    {
        if ($json || $request->expectsJson() || $request->ajax()) {
            return $this->fail($msg, ApiResponse::VALIDATION_FAILED);
        }

        return redirect()->back()->withInput()->with('error', $msg);
    }
}
