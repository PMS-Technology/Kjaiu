<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Host;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\TicketStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Support tickets (`shd_ticket` / `shd_ticket_reply`).
 *
 * Ticket statuses are administrator-editable rows in `shd_ticket_status`, not
 * an enum, so labels and colours are always resolved from that table.
 * Departments may be linked to an upstream supplier
 * (`shd_ticket_department_upstream`), in which case a customer reply is
 * forwarded to the supplier installation as well.
 */
class TicketService
{
    /** Priority keys the client area uses, lower-cased on submit. */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /** `shd_system_message.type` values. */
    public const MESSAGE_WORK_ORDER = 1;
    public const MESSAGE_PRODUCT = 2;
    public const MESSAGE_IN_SITE = 3;
    public const MESSAGE_EVENT = 4;

    /** Message type => API key name. */
    public const MESSAGE_TYPES = [
        self::MESSAGE_WORK_ORDER => 'work_order_message',
        self::MESSAGE_PRODUCT => 'product_news',
        self::MESSAGE_IN_SITE => 'on_site_news',
        self::MESSAGE_EVENT => 'event_news',
    ];

    /** Allowed attachment suffixes for ticket uploads. */
    public const ALLOWED_SUFFIXES = ['jpg', 'jpeg', 'gif', 'png', 'zip', 'rar', 'pdf', 'txt', 'log'];

    public function __construct(
        protected ModuleService $modules = new ModuleService(),
    ) {
    }

    /**
     * Paginated ticket list for a client.
     */
    public function paginate(Client $client, int $page, int $limit, array $filters = [])
    {
        $query = Ticket::query()
            ->where('uid', $client->id)
            ->with(['department', 'host.product']);

        if (! empty($filters['status'])) {
            $query->where('status', (int) $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('dptid', (int) $filters['department_id']);
        }

        if (! empty($filters['keywords'])) {
            $keywords = (string) $filters['keywords'];
            $query->where(function ($sub) use ($keywords) {
                $sub->where('title', 'like', '%' . $keywords . '%')
                    ->orWhere('tid', 'like', '%' . $keywords . '%')
                    ->orWhere('id', $keywords);
            });
        }

        $orderby = (string) ($filters['orderby'] ?? 'id');
        $sort = strtoupper((string) ($filters['sort'] ?? 'DESC')) === 'ASC' ? 'asc' : 'desc';

        if (! preg_match('/^[A-Za-z0-9_]+$/', $orderby)) {
            $orderby = 'id';
        }

        return $query->orderBy($orderby, $sort)->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Ticket-list row.
     */
    public function listPayload(Ticket $ticket): array
    {
        $status = $this->statusRow((int) $ticket->status);
        $host = $ticket->host;

        return [
            'id' => (int) $ticket->id,
            'tid' => (string) $ticket->tid,
            'uid' => (int) $ticket->uid,
            'department_id' => (int) $ticket->dptid,
            'department_name' => (string) ($ticket->department?->name ?? ''),
            'host_id' => (int) $ticket->host_id,
            'product_name' => $host === null ? '' : $this->hostName($host),
            'name' => (string) $ticket->name,
            'email' => (string) $ticket->email,
            'title' => (string) $ticket->title,
            'content' => (string) $ticket->content,
            'status' => (string) $ticket->status,
            'status_zh' => $status,
            'priority' => (string) $ticket->priority,
            'admin' => (string) $ticket->admin,
            'attachment' => $ticket->attachmentList(),
            'client_unread' => (int) $ticket->client_unread,
            'admin_unread' => (int) $ticket->admin_unread,
            'create_time' => (int) $ticket->create_time,
            'update_time' => (int) $ticket->update_time,
            'last_reply_time' => (int) $ticket->last_reply_time,
        ];
    }

    /**
     * Departments the client may submit a ticket to, with their custom fields.
     */
    public function departments(Client $client): array
    {
        $departments = TicketDepartment::query()
            ->where('hidden', 0)
            ->orderBy('order')
            ->get();

        $fields = $this->customFields();

        return $departments->map(function (TicketDepartment $department) use ($fields, $client) {
            if ((int) $department->only_client_open === 1 && ! $client->isActive()) {
                return null;
            }

            return [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
                'description' => (string) $department->description,
                'custom_fields' => $fields,
            ];
        })->filter()->values()->all();
    }

    /**
     * Ticket custom field definitions (`shd_customfields` where type=ticket).
     */
    public function customFields(): array
    {
        return CustomField::query()
            ->where('type', 'ticket')
            ->orderBy('sortorder')
            ->get()
            ->map(fn (CustomField $field) => [
                'id' => (int) $field->id,
                'name' => (string) $field->fieldname,
                'fieldname' => (string) $field->fieldname,
                'type' => (string) $field->fieldtype,
                'fieldtype' => (string) $field->fieldtype,
                'description' => (string) $field->description,
                'options' => $field->options(),
                'regexpr' => (string) $field->regexpr,
                'required' => (int) $field->required,
            ])
            ->values()
            ->all();
    }

    /**
     * A client's services, for the ticket's "related product" select.
     */
    public function hostOptions(Client $client): array
    {
        return Host::query()
            ->where('uid', $client->id)
            ->whereIn('domainstatus', Host::LIVE_STATUSES)
            ->with('product')
            ->orderBy('id')
            ->get()
            ->map(fn (Host $host) => [
                'id' => (int) $host->id,
                'product_name' => $this->productName($host),
                'name' => (string) $host->domain,
                'status' => (string) $host->domainstatus,
                'ip' => (string) $host->dedicatedip,
            ])
            ->values()
            ->all();
    }

    /**
     * Create a ticket from a validated request.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Client $client, array $attributes): Ticket
    {
        $department = TicketDepartment::query()->find((int) $attributes['department_id']);

        if ($department === null) {
            throw new \InvalidArgumentException('工单部门不存在');
        }

        $hostId = (int) ($attributes['host_id'] ?? 0);

        if ($hostId > 0) {
            $owned = Host::query()->where('id', $hostId)->where('uid', $client->id)->exists();

            if (! $owned) {
                throw new \InvalidArgumentException('关联产品不存在');
            }
        }

        $priority = strtolower((string) ($attributes['priority'] ?? 'medium'));

        if (! in_array($priority, self::PRIORITIES, true)) {
            $priority = 'medium';
        }

        $attachments = $this->normaliseAttachments($attributes['attachment'] ?? []);

        $ticket = Ticket::create([
            'tid' => $this->uniqueToken(),
            'dptid' => (int) $department->id,
            'uid' => (int) $client->id,
            'host_id' => $hostId,
            'name' => (string) ($client->username ?: $client->email),
            'email' => (string) $client->email,
            'create_time' => time(),
            'update_time' => time(),
            'title' => (string) $attributes['title'],
            'content' => (string) $attributes['content'],
            'status' => $this->defaultStatus(),
            'priority' => $priority,
            'admin' => '',
            'admin_id' => 0,
            'attachment' => $attachments === [] ? '' : json_encode($attachments, JSON_UNESCAPED_UNICODE),
            'last_reply_time' => time(),
            'client_unread' => 0,
            'admin_unread' => 1,
            'service' => '',
            'c' => substr(bin2hex(random_bytes(10)), 0, 20),
            'flag' => 0,
            'star' => 0,
            'is_auto_reply' => 0,
            'is_deliver' => 0,
            'upstream_tid' => '',
            'is_receive' => 0,
            'handle' => 0,
            'handle_time' => 0,
            'token' => substr(bin2hex(random_bytes(16)), 0, 32),
        ]);

        $this->storeCustomFields($ticket, $client, (array) ($attributes['custom_fields'] ?? []));

        $this->notifyDepartment($ticket, $department);

        return $ticket;
    }

    /**
     * Reply to a ticket as the customer.
     */
    public function reply(Ticket $ticket, Client $client, string $content, array $attachment = []): TicketReply
    {
        $attachments = $this->normaliseAttachments($attachment);

        $reply = TicketReply::create([
            'tid' => (int) $ticket->id,
            'uid' => (int) $client->id,
            'contactid' => 0,
            'create_time' => time(),
            'content' => $content,
            'admin' => '',
            'admin_id' => 0,
            'attachment' => $attachments === [] ? '' : json_encode($attachments, JSON_UNESCAPED_UNICODE),
            'star' => 0,
            'editor' => 'plain',
            'is_deliver' => 0,
            'is_receive' => 0,
            'source' => 0,
        ]);

        // A customer reply reopens a closed ticket, as the client area does.
        $ticket->status = (string) $ticket->status === (string) $this->closedStatusId()
            ? $this->defaultStatus()
            : $ticket->status;

        $ticket->last_reply_time = time();
        $ticket->update_time = time();
        $ticket->admin_unread = 1;
        $ticket->client_unread = 0;
        $ticket->save();

        $this->forwardToUpstream($ticket, $client, $content, $attachments);

        return $reply;
    }

    /**
     * Ticket detail with its replies.
     */
    public function detailPayload(Ticket $ticket, bool $forClient = true): array
    {
        $ticket->load(['replies', 'department', 'host.product']);

        $replies = $ticket->replies->map(fn (TicketReply $reply) => [
            'id' => (int) $reply->id,
            'type' => 'reply',
            'uid' => (int) $reply->uid,
            'user_id' => (int) ($reply->admin_id ?: $reply->uid),
            'user_type' => $reply->isStaffReply() ? 'admin' : 'user',
            'user' => $reply->isStaffReply() ? (string) $reply->admin : (string) ($ticket->name ?: $ticket->email),
            'admin' => (string) $reply->admin,
            'content' => (string) $reply->content,
            'attachment' => $reply->attachmentList(),
            'star' => (int) $reply->star,
            'create_time' => (int) $reply->create_time,
        ])->values()->all();

        $status = $this->statusRow((int) $ticket->status);

        $payload = $this->listPayload($ticket);
        $payload['replies'] = $replies;
        $payload['reply'] = $replies;
        $payload['host'] = $ticket->host === null ? '' : [
            'id' => (int) $ticket->host->id,
            'name' => (string) $ticket->host->domain,
            'product_name' => $this->productName($ticket->host),
        ];
        $payload['feedback_request'] = (int) Configuration::value('evaluate_ticket', 1);

        if ($forClient) {
            $payload['c'] = (string) $ticket->c;
        }

        return $payload;
    }

    /**
     * Clear the client-side unread marker.
     */
    public function markClientRead(Ticket $ticket): void
    {
        if ((int) $ticket->client_unread === 0) {
            return;
        }

        $ticket->client_unread = 0;
        $ticket->save();
    }

    /**
     * Ticket status row, admin-editable.
     */
    public function statusRow(int $statusId): array
    {
        static $cache = [];

        if (! isset($cache[$statusId])) {
            $row = TicketStatus::query()->find($statusId);

            $cache[$statusId] = [
                'id' => $statusId,
                'title' => (string) ($row->title ?? ''),
                'color' => (string) ($row->color ?? ''),
            ];
        }

        return $cache[$statusId];
    }

    /**
     * Every ticket status, for filters.
     */
    public function statuses(): array
    {
        return TicketStatus::query()
            ->orderBy('order')
            ->get()
            ->map(fn (TicketStatus $status) => [
                'id' => (int) $status->id,
                'title' => (string) $status->title,
                'color' => (string) $status->color,
            ])
            ->values()
            ->all();
    }

    /**
     * Status a freshly created ticket starts in ("待处理" by default).
     */
    public function defaultStatus(): int
    {
        $row = TicketStatus::query()->orderBy('order')->orderBy('id')->first();

        return (int) ($row->id ?? 1);
    }

    /**
     * The status row flagged as the closed state.
     */
    public function closedStatusId(): int
    {
        $row = TicketStatus::query()->where('title', '关闭')->first();

        return (int) ($row->id ?? 4);
    }

    /**
     * Forward a customer reply to the supplier when the department is linked
     * to an upstream installation and the ticket is on an upstream service.
     */
    public function forwardToUpstream(Ticket $ticket, Client $client, string $content, array $attachments = []): void
    {
        $link = DB::table('ticket_department_upstream')
            ->where('dptid', (int) $ticket->dptid)
            ->first();

        if ($link === null) {
            return;
        }

        $host = $ticket->host;

        // Only tickets raised against a resold service travel upstream.
        if ($host === null || ! $this->modules->isUpstream($host)) {
            return;
        }

        $supplier = $this->modules->supplierClient($host);

        if ($supplier === null) {
            return;
        }

        try {
            $result = $supplier->request('post', '/v1/tickets', [
                'department_id' => (int) $link->upstream_dptid,
                'host_id' => (int) $host->id,
                'title' => (string) $ticket->title,
                'content' => $content,
                'attachment' => $attachments,
                'upstream_tid' => (string) $ticket->upstream_tid,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Ticket could not be forwarded to the upstream supplier', [
                'ticket' => $ticket->id,
                'supplier' => (int) $link->api_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $upstreamId = $result['data']['id'] ?? $result['data']['tid'] ?? null;

        if ($upstreamId !== null && (string) $ticket->upstream_tid === '') {
            $ticket->upstream_tid = (string) $upstreamId;
            $ticket->is_deliver = 1;
            $ticket->save();
        }
    }

    /**
     * Attachments as `[{path, name}]`, dropping anything outside the allowlist.
     */
    public function normaliseAttachments(mixed $attachment): array
    {
        if (is_string($attachment)) {
            $decoded = json_decode($attachment, true);
            $attachment = is_array($decoded) ? $decoded : ($attachment === '' ? [] : [$attachment]);
        }

        if (! is_array($attachment)) {
            return [];
        }

        $allowed = array_map(
            'strtolower',
            array_filter(array_map('trim', explode(',', (string) Configuration::value(
                'ticket_attachment_suffix',
                implode(',', self::ALLOWED_SUFFIXES)
            ))))
        );

        $out = [];

        foreach ($attachment as $item) {
            if (is_array($item)) {
                // Already normalised by an upload endpoint.
                $out[] = [
                    'path' => (string) ($item['path'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                ];

                continue;
            }

            $value = trim((string) $item);

            if ($value === '') {
                continue;
            }

            // The client area stores "path^display name".
            $parts = explode('^', $value, 2);
            $path = trim($parts[0]);
            $name = trim($parts[1] ?? basename($path));
            $suffix = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($allowed !== [] && $suffix !== '' && ! in_array($suffix, $allowed, true)) {
                continue;
            }

            $out[] = ['path' => $path, 'name' => $name];
        }

        return $out;
    }

    /**
     * Persist ticket custom-field values.
     *
     * @param  array<int|string, mixed>  $values  field id => value
     */
    protected function storeCustomFields(Ticket $ticket, Client $client, array $values): void
    {
        if ($values === []) {
            return;
        }

        $fields = CustomField::query()
            ->where('type', 'ticket')
            ->whereIn('id', array_map('intval', array_keys($values)))
            ->get()
            ->keyBy('id');

        foreach ($values as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);

            if ($field === null) {
                continue;
            }

            $stored = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;

            CustomFieldValue::create([
                'fieldid' => (int) $field->id,
                'relid' => (int) $ticket->id,
                'value' => $stored,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    /**
     * `tid` token: date prefix plus randomness, unique per ticket.
     */
    protected function uniqueToken(): string
    {
        do {
            $token = date('Ymd') . strtoupper(Str::random(8));
        } while (Ticket::query()->where('tid', $token)->exists());

        return $token;
    }

    /**
     * Drop an in-site message into the department administrator's queue so the
     * admin panel shows the new ticket immediately.
     */
    protected function notifyDepartment(Ticket $ticket, TicketDepartment $department): void
    {
        $status = $this->statusRow((int) $ticket->status);

        DB::table('system_message')->insert([
            'uid' => (int) $ticket->uid,
            'title' => '工单提交成功：#' . $ticket->id . ' ' . $ticket->title,
            'content' => '您的工单已提交至「' . $department->name . '」，当前状态：' . ($status['title'] ?: '待处理'),
            'obj' => json_encode(['ticket_id' => (int) $ticket->id, 'tid' => (string) $ticket->tid], JSON_UNESCAPED_UNICODE),
            'attachment' => '',
            'type' => self::MESSAGE_WORK_ORDER,
            'is_market' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'read_time' => 0,
        ]);
    }

    protected function hostName(Host $host): string
    {
        $product = $host->product;
        $group = $product?->group;

        return $group === null
            ? (string) ($product->name ?? ('服务 #' . $host->id))
            : ($group->name . '-' . $product->name);
    }

    protected function productName(Host $host): string
    {
        return $this->hostName($host);
    }

    /**
     * Resolve the ticket referenced by `{id}`, which the client area passes as
     * either the numeric id or the `tid` token.
     */
    public function resolve(Client $client, string $identifier): ?Ticket
    {
        $query = Ticket::query()->where('uid', $client->id);

        if (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            $query->where('tid', $identifier);
        }

        return $query->first();
    }

    /**
     * Client-area page data for the submit form.
     */
    public function submitPage(Request $request, Client $client): array
    {
        return [
            'host' => $this->hostOptions($client),
            'priority' => self::PRIORITIES,
            'department' => $this->departments($client),
            'custom_fields' => $this->customFields(),
        ];
    }
}
