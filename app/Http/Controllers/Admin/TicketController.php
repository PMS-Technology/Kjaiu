<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\Host;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketStatus;
use App\Services\Admin\AdminMeta;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 工单 — the ticket list/detail, replies, notes, transfer, departments,
 * statuses, predefined replies and the transfer rules.
 */
class TicketController extends AdminController
{
    /**
     * `GET list_ticket` — the 工单列表.
     *
     * The payload carries the status dictionary alongside the rows, which the
     * table needs to colour its status column.
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'create_time', 'last_reply_time', 'status'], 'id');

        $query = Ticket::query();

        $this->applyFilters($query, $request);

        $total = (clone $query)->count();
        $rows = $query->orderBy($orderBy, $sort)->forPage($page, $limit)->get();

        $list = $this->decorate($rows);

        return $this->ok([
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'sum' => $total,
            'page' => $page,
            'limit' => $limit,
            'max_page' => (int) ceil($total / max(1, $limit)),
            'ticket_status' => AdminMeta::ticketStatuses(),
        ]);
    }

    /**
     * `POST searchfornamelist` (ticket flavour).
     */
    public function searchForNameList(Request $request)
    {
        return $this->list($request);
    }

    /**
     * `GET list_ticket/<id>` — 工单详情.
     */
    public function detail(Request $request, $id)
    {
        $ticket = Ticket::query()->find((int) $id);

        if ($ticket === null) {
            return $this->notFound('工单不存在');
        }

        $client = Client::query()->find($ticket->uid);

        $replies = TicketReply::query()
            ->where('tid', $ticket->id)
            ->orderBy('id')
            ->get()
            ->map(function (TicketReply $reply) {
                $row = $reply->toArray();
                $row['is_admin'] = $reply->admin !== '' && $reply->admin !== null;
                $row['admin_name'] = $reply->admin;
                $row['user_name'] = Client::query()->whereKey($reply->uid)->value('username');

                return $row;
            })
            ->all();

        $notes = DB::table('ticket_note')
            ->where('tid', $ticket->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($n) => (array) $n)
            ->all();

        $hosts = Host::query()
            ->where('uid', $ticket->uid)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Host $h) => [
                'id' => (int) $h->id,
                'productname' => $h->product?->name,
                'domain' => $h->domain,
                'amount' => $this->money((float) $h->amount),
                'billingcycle' => (string) $h->billingcycle,
                'create_time' => (int) $h->create_time,
                'nextduedate' => (int) $h->nextduedate,
                'domainstatus' => (string) $h->domainstatus,
                'domainstatus_zh' => AdminMeta::domainStatusLabel((string) $h->domainstatus),
            ])
            ->all();

        return $this->ok([
            'ticket' => AdminMeta::ticketRow($ticket) + ['content' => $ticket->content],
            'client' => $client ? (array) $client->toArray() : null,
            'reply' => $replies,
            'notes' => $notes,
            'hosts' => $hosts,
            'ticket_status' => AdminMeta::ticketStatuses(),
            'department' => DB::table('ticket_department')->get()->toArray(),
            'handle' => DB::table('user')->where('user_status', 1)->get(['id', 'user_login', 'user_nickname'])->toArray(),
        ]);
    }

    /**
     * `GET list_ticket_status` — the (admin editable) status list.
     */
    public function statusList(Request $request)
    {
        return $this->ok(AdminMeta::ticketStatuses());
    }

    /**
     * `GET list_ticket_status/<id>`
     */
    public function statusDetail(Request $request, $id)
    {
        $row = TicketStatus::query()->find((int) $id);

        return $row === null ? $this->notFound('状态不存在') : $this->ok($row->toArray());
    }

    /**
     * `POST add_ticket_status`
     */
    public function statusCreate(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('状态标题不能为空');
        }

        $status = TicketStatus::query()->create([
            'title' => $title,
            'color' => (string) $request->input('color', '#1881EB'),
            'order' => (int) $request->input('order', 0),
            'show_active' => (int) $request->input('show_active', 1),
            'show_await' => (int) $request->input('show_await', 1),
            'auto_close' => (int) $request->input('auto_close', 1),
        ]);

        $this->log('添加工单状态：'.$title, (int) $status->id);

        return $this->ok(['id' => (int) $status->id], '添加成功');
    }

    /**
     * `POST save_ticket_status`
     */
    public function statusUpdate(Request $request)
    {
        $status = TicketStatus::query()->find((int) $request->input('id', 0));

        if ($status === null) {
            return $this->notFound('状态不存在');
        }

        foreach (['title', 'color'] as $field) {
            if ($request->has($field)) {
                $status->{$field} = (string) $request->input($field);
            }
        }

        foreach (['order', 'show_active', 'show_await', 'auto_close'] as $field) {
            if ($request->has($field)) {
                $status->{$field} = (int) $request->input($field);
            }
        }

        $status->save();

        return $this->ok(['id' => (int) $status->id], '保存成功');
    }

    /**
     * `POST delete_ticket_status`
     */
    public function statusDelete(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if (Ticket::query()->where('status', $id)->exists()) {
            return $this->fail('该状态下还有工单，不能删除');
        }

        TicketStatus::query()->whereKey($id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET list_ticket_department` / `GET getTicketDepartment`
     */
    public function departmentList(Request $request)
    {
        $rows = DB::table('ticket_department')->orderBy('order')->get()->map(function ($d) {
            $row = (array) $d;
            $row['count'] = Ticket::query()->where('dptid', $d->id)->count();
            $row['department_name'] = $d->name;

            return $row;
        })->all();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'data' => $rows,
            'list' => $rows,
        ] + $this->envelope());
    }

    /**
     * `POST add_ticket_department`
     */
    public function departmentCreate(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('部门名称不能为空');
        }

        $id = (int) DB::table('ticket_department')->insertGetId([
            'name' => $name,
            'description' => (string) $request->input('description', ''),
            'email' => (string) $request->input('email', ''),
            'hidden' => (int) $request->input('hidden', 0),
            'only_reg_client' => (int) $request->input('only_reg_client', 0),
            'only_client_open' => (int) $request->input('only_client_open', 0),
            'no_auto_reply' => (int) $request->input('no_auto_reply', 0),
            'is_open_auto_reply' => (int) $request->input('is_open_auto_reply', 0),
            'bz' => (string) $request->input('bz', ''),
            'minutes' => (int) $request->input('minutes', 0),
            'order' => (int) DB::table('ticket_department')->max('order') + 1,
        ]);

        $this->log('添加工单部门：'.$name, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST save_ticket_department` — the inline row editor and the full form.
     */
    public function departmentUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('ticket_department')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('部门不存在');
        }

        $data = [];

        $strings = ['name', 'description', 'email', 'bz'];

        foreach ($strings as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        $ints = ['hidden', 'only_reg_client', 'only_client_open', 'no_auto_reply', 'is_open_auto_reply', 'minutes', 'order', 'is_related_upstream', 'is_certifi'];

        foreach ($ints as $field) {
            if ($request->has($field)) {
                $data[$field] = (int) $request->input($field);
            }
        }

        if ($data === []) {
            return $this->validationFail('没有需要保存的内容');
        }

        DB::table('ticket_department')->where('id', $id)->update($data);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `POST delete_ticket_department`
     */
    public function departmentDelete(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if (Ticket::query()->where('dptid', $id)->exists()) {
            return $this->fail('该部门下还有工单，不能删除');
        }

        DB::table('ticket_department')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST moveup_ticket_department` / `POST movedown_ticket_department`
     */
    public function departmentMoveUp(Request $request)
    {
        return $this->moveDepartment($request, -1);
    }

    public function departmentMoveDown(Request $request)
    {
        return $this->moveDepartment($request, 1);
    }

    /**
     * `GET download_ticket_attachment {file}`
     */
    public function downloadAttachment(Request $request)
    {
        $file = (string) $request->input('file', $request->input('path', ''));

        if ($file === '') {
            return $this->fail('ID错误');
        }

        // Attachments live under the public uploads directory; refuse anything
        // that escapes it.
        $relative = ltrim(str_replace(['..', '\\'], '', $file), '/');
        $absolute = public_path('uploads/'.$relative);

        if (! is_file($absolute)) {
            $absolute = public_path($relative);
        }

        if (! is_file($absolute)) {
            return $this->fail('文件不存在');
        }

        return response()->download($absolute);
    }

    /**
     * `GET add_ticket_page` — 新建工单 form metadata.
     */
    public function createPage(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->ok([
            'department' => DB::table('ticket_department')->where('hidden', 0)->orderBy('order')->get()->toArray(),
            'ticket_status' => AdminMeta::ticketStatuses(),
            'custom_fields' => DB::table('ticket_department')->get(['id', 'name'])->toArray(),
            'priority' => $this->priorities(),
            'client' => $uid > 0 ? (array) (Client::query()->find($uid)?->toArray() ?? []) : null,
            'hosts' => Host::query()->when($uid > 0, fn ($q) => $q->where('uid', $uid))->limit(100)->get(['id', 'domain', 'productid'])->toArray(),
        ]);
    }

    /**
     * `POST add_ticket` / `POST save_ticket` — open a ticket from the admin.
     */
    public function create(Request $request)
    {
        return $this->save($request);
    }

    /**
     * `POST save_ticket` — create a ticket on behalf of a client.
     */
    public function save(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->validationFail('请选择客户');
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('工单标题不能为空');
        }

        $now = time();

        $ticket = Ticket::query()->create([
            'tid' => (string) Str::uuid(),
            'dptid' => (int) $request->input('dptid', 0),
            'uid' => $uid,
            'host_id' => (int) $request->input('host_id', 0),
            'name' => $client->username,
            'email' => $client->email,
            'create_time' => $now,
            'update_time' => $now,
            'title' => $title,
            'content' => (string) $request->input('content', ''),
            'status' => (int) $request->input('status', $this->defaultStatusId()),
            'priority' => (string) $request->input('priority', 'medium'),
            'admin' => $this->adminName(),
            'admin_id' => $this->adminId(),
            'last_reply_time' => $now,
            'admin_unread' => 0,
            'client_unread' => 1,
            'attachment' => is_array($request->input('attachment'))
                ? implode(',', $request->input('attachment'))
                : (string) $request->input('attachment', ''),
            'c' => (string) $request->input('c', ''),
            'cc' => is_array($request->input('cc'))
                ? implode(',', $request->input('cc'))
                : (string) $request->input('cc', ''),
        ]);

        $this->log('创建工单 #'.$ticket->id, (int) $ticket->id);

        return $this->ok([
            'id' => (int) $ticket->id,
            'tid' => $ticket->tid,
        ], '创建成功');
    }

    /**
     * `POST reply_ticket` — an administrator reply.
     */
    public function reply(Request $request)
    {
        $ticket = Ticket::query()->find((int) $request->input('id', 0));

        if ($ticket === null) {
            return $this->notFound('工单不存在');
        }

        $content = (string) $request->input('content', '');

        if (trim($content) === '') {
            return $this->validationFail('回复内容不能为空');
        }

        $now = time();

        $reply = TicketReply::query()->create([
            'tid' => (int) $ticket->id,
            'uid' => (int) $ticket->uid,
            'contactid' => 0,
            'create_time' => $now,
            'content' => $content,
            'admin' => $this->adminName(),
            'admin_id' => $this->adminId(),
            'attachment' => is_array($request->input('attachment'))
                ? implode(',', $request->input('attachment'))
                : (string) $request->input('attachment', ''),
            'editor' => (string) $request->input('editor', 'plain'),
        ]);

        $ticket->last_reply_time = $now;
        $ticket->client_unread = 1;
        $ticket->admin_unread = 0;
        $ticket->update_time = $now;

        // `replyAndClose` posts a status alongside the reply.
        if ($request->filled('status')) {
            $ticket->status = (int) $request->input('status');
        }

        $ticket->save();

        $this->log('回复工单 #'.$ticket->id, (int) $ticket->id);

        return $this->ok(['id' => (int) $reply->id], '回复成功');
    }

    /**
     * `POST save_ticket_reply` — edit an existing reply.
     */
    public function saveReply(Request $request)
    {
        $reply = TicketReply::query()->find((int) $request->input('id', 0));

        if ($reply === null) {
            return $this->notFound('回复不存在');
        }

        $reply->content = (string) $request->input('content', $reply->content);
        $reply->save();

        return $this->ok(['id' => (int) $reply->id], '保存成功');
    }

    /**
     * `POST delete_ticket_reply`
     */
    public function deleteReply(Request $request)
    {
        $id = (int) $request->input('id', 0);

        TicketReply::query()->whereKey($id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST add_ticket_note` — internal note (`shd_ticket_note`).
     */
    public function addNote(Request $request)
    {
        $ticket = Ticket::query()->find((int) $request->input('id', 0));

        if ($ticket === null) {
            return $this->notFound('工单不存在');
        }

        $content = (string) $request->input('content', '');

        if (trim($content) === '') {
            return $this->validationFail('备注内容不能为空');
        }

        $id = (int) DB::table('ticket_note')->insertGetId([
            'tid' => (int) $ticket->id,
            'admin' => $this->adminName(),
            'create_time' => time(),
            'content' => $content,
            'attachment' => is_array($request->input('attachment'))
                ? implode(',', $request->input('attachment'))
                : (string) $request->input('attachment', ''),
            'editor' => (string) $request->input('editor', 'plain'),
        ]);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST delete_ticket_note`
     */
    public function deleteNote(Request $request)
    {
        DB::table('ticket_note')->where('id', (int) $request->input('id', 0))->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST close_ticket`
     */
    public function close(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        $closedStatus = $this->closedStatusId();

        Ticket::query()->whereIn('id', $ids)->update([
            'status' => $closedStatus,
            'update_time' => time(),
        ]);

        $this->log('关闭工单：'.implode(',', $ids));

        return $this->ok(['ids' => array_values($ids)], '关闭成功');
    }

    /**
     * `POST delete_ticket`
     */
    public function delete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::transaction(function () use ($ids) {
            Ticket::query()->whereIn('id', $ids)->delete();
            TicketReply::query()->whereIn('tid', $ids)->delete();
            DB::table('ticket_note')->whereIn('tid', $ids)->delete();
        });

        $this->log('删除工单：'.implode(',', $ids));

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    /**
     * `POST merge_ticket` — fold several tickets into one thread.
     */
    public function merge(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if (count($ids) < 2) {
            return $this->validationFail('请至少选择两个工单');
        }

        $tickets = Ticket::query()->whereIn('id', $ids)->orderBy('id')->get();

        if ($tickets->count() < 2) {
            return $this->notFound('工单不存在');
        }

        $target = $tickets->first();
        $others = $tickets->reject(fn (Ticket $t) => $t->id === $target->id);

        DB::transaction(function () use ($target, $others) {
            foreach ($others as $ticket) {
                DB::table('ticket_reply')->where('tid', $ticket->id)->update(['tid' => $target->id]);
                DB::table('ticket_note')->where('tid', $ticket->id)->update(['tid' => $target->id]);

                $ticket->merged_ticket_id = $target->id;
                $ticket->status = $this->closedStatusId();
                $ticket->update_time = time();
                $ticket->save();
            }

            $target->update_time = time();
            $target->save();
        });

        $this->log('合并工单：'.implode(',', $ids).' → '.$target->id, (int) $target->id);

        return $this->ok(['id' => (int) $target->id], '合并成功');
    }

    /**
     * `PUT ticket_receive` — 接单 (claim a ticket).
     */
    public function receive(Request $request)
    {
        $ticket = Ticket::query()->find((int) $request->input('id', 0));

        if ($ticket === null) {
            return $this->notFound('工单不存在');
        }

        if ((int) $ticket->is_receive === 1 && (int) $ticket->handle !== $this->adminId()) {
            return $this->fail('该工单已被其他人接单');
        }

        $ticket->is_receive = 1;
        $ticket->handle = $this->adminId();
        $ticket->handle_time = time();
        $ticket->admin = $this->adminName();
        $ticket->admin_id = $this->adminId();
        $ticket->update_time = time();
        $ticket->save();

        $this->log('接单工单 #'.$ticket->id, (int) $ticket->id);

        return $this->ok(['id' => (int) $ticket->id], '接单成功');
    }

    /**
     * `PUT ticket_transfer` — 转单 to another administrator or department.
     */
    public function transfer(Request $request)
    {
        $ticket = Ticket::query()->find((int) $request->input('id', 0));

        if ($ticket === null) {
            return $this->notFound('工单不存在');
        }

        // `mode` 0 transfers to a department, 1 to a named handler.
        $mode = (string) $request->input('mode', 'handle');
        $remarks = (string) $request->input('remarks', '');

        $data = ['update_time' => time()];
        $target = '';
        $modeFlag = 1;

        if ($mode === 'dptid') {
            $dptid = (int) $request->input('dptid', 0);
            $data['dptid'] = $dptid;
            $modeFlag = 0;
            $target = (string) (DB::table('ticket_department')->where('id', $dptid)->value('name') ?? '');
        } else {
            $handle = (int) $request->input('handle', 0);
            $data['handle'] = $handle;
            $data['admin_id'] = $handle;
            $data['admin'] = (string) (DB::table('user')->where('id', $handle)->value('user_login') ?? '');
            $data['is_receive'] = 1;
            $target = $data['admin'];
        }

        Ticket::query()->whereKey($ticket->id)->update($data);

        DB::table('ticket_transfer_log')->insert([
            'tid' => (int) $ticket->id,
            'desc' => '由'.$this->adminName().'转单给'.$target,
            'remarks' => $remarks,
            'mode' => $modeFlag,
            'old_handle' => (int) $ticket->handle,
            'handle' => (int) ($data['handle'] ?? 0),
            'old_dptid' => (int) $ticket->dptid,
            'dptid' => (int) ($data['dptid'] ?? $ticket->dptid),
            'admin' => $this->adminId(),
            'create_time' => time(),
        ]);

        $this->log('转单工单 #'.$ticket->id.' → '.$target, (int) $ticket->id);

        return $this->ok(['id' => (int) $ticket->id], '转单成功');
    }

    /**
     * `GET ticket_transfer_list` — the 转单 history.
     */
    public function transferList(Request $request)
    {
        $tid = (int) $request->input('tid', $request->input('id', 0));

        $rows = DB::table('ticket_transfer_log')
            ->leftJoin('user', 'user.id', '=', 'ticket_transfer_log.admin')
            ->when($tid > 0, fn ($q) => $q->where('ticket_transfer_log.tid', $tid))
            ->orderByDesc('ticket_transfer_log.id')
            ->limit(200)
            ->get(['l.*', 'user.user_login as admin_name'])
            ->map(fn ($r) => (array) $r)
            ->all();

        return $this->ok($rows);
    }

    /**
     * `GET ticket_detail_host {uid}` — the 关联产品 sub-table.
     */
    public function detailHost(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        $rows = Host::query()
            ->when($uid > 0, fn ($q) => $q->where('uid', $uid))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Host $h) => [
                'id' => (int) $h->id,
                'productname' => $h->product?->name,
                'domain' => $h->domain,
                'amount' => $this->money((float) $h->amount),
                'billingcycle' => (string) $h->billingcycle,
                'create_time' => (int) $h->create_time,
                'nextduedate' => (int) $h->nextduedate,
                'domainstatus' => (string) $h->domainstatus,
                'domainstatus_zh' => AdminMeta::domainStatusLabel((string) $h->domainstatus),
            ])
            ->all();

        return $this->ok(['hosts' => $rows, 'products' => $rows]);
    }

    /**
     * `GET ticket/statistics` — 工单统计.
     */
    public function statistics(Request $request)
    {
        $byStatus = [];

        foreach (AdminMeta::ticketStatuses() as $status) {
            $byStatus[] = [
                'id' => (int) $status['id'],
                'title' => $status['title'],
                'color' => $status['color'],
                'count' => Ticket::query()->where('status', $status['id'])->count(),
            ];
        }

        $byDepartment = DB::table('ticket_department')->get()->map(function ($d) {
            return [
                'id' => (int) $d->id,
                'name' => $d->name,
                'count' => Ticket::query()->where('dptid', $d->id)->count(),
            ];
        })->all();

        $start = $this->timestamp($request->input('start_time')) ?? strtotime('-30 days');
        $end = $this->timestamp($request->input('end_time')) ?? time();

        return $this->ok([
            'total' => Ticket::query()->count(),
            'by_status' => $byStatus,
            'by_department' => $byDepartment,
            'today' => Ticket::query()->whereBetween('create_time', [strtotime('today'), time()])->count(),
            'range' => Ticket::query()->whereBetween('create_time', [$start, $end])->count(),
        ]);
    }

    // -----------------------------------------------------------------
    // 工单传递规则
    // -----------------------------------------------------------------

    /**
     * `GET get_ticket_deliver` / `GET list_ticket_deliver`
     */
    public function deliverList(Request $request)
    {
        $rows = DB::table('ticket_deliver')->get()->map(function ($rule) {
            $row = (array) $rule;
            $row['departments'] = DB::table('ticket_deliver_department')
                ->join('ticket_department', 'ticket_department.id', '=', 'ticket_deliver_department.dptid')
                ->where('ticket_deliver_department.tdid', $rule->id)
                ->pluck('ticket_department.name')
                ->all();
            $row['products'] = DB::table('ticket_deliver_products')
                ->join('products', 'products.id', '=', 'ticket_deliver_products.pid')
                ->where('ticket_deliver_products.tdid', $rule->id)
                ->pluck('products.name')
                ->all();

            return $row;
        })->all();

        return $this->ok($rows);
    }

    /**
     * `POST add_ticket_deliver`
     */
    public function deliverCreate(Request $request)
    {
        $id = (int) DB::table('ticket_deliver')->insertGetId([
            'mask_keywords' => (string) $request->input('mask_keywords', ''),
            'is_open_auto_reply' => (int) $request->input('is_open_auto_reply', 0),
            'bz' => (string) $request->input('bz', ''),
        ]);

        $this->syncDeliverRelations($id, $request);

        return $this->ok(['id' => $id], '添加成功');
    }

    // -----------------------------------------------------------------
    // 预定义回复
    // -----------------------------------------------------------------

    /**
     * `GET ticket_prereply_list` / `POST search_ticket_prereply`
     */
    public function prereplyList(Request $request)
    {
        $categories = DB::table('ticket_prereply_category')->orderBy('id')->get();

        $list = $categories->map(function ($category) {
            return [
                'id' => (int) $category->id,
                'title' => $category->name,
                'name' => $category->name,
                'parentid' => (int) $category->parentid,
                'content' => '',
                'children' => DB::table('ticket_prereply')
                    ->where('cid', $category->id)
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn ($p) => [
                        'id' => (int) $p->id,
                        'cid' => (int) $p->cid,
                        'title' => $p->title,
                        'content' => $p->content,
                    ])
                    ->all(),
            ];
        })->all();

        return $this->ok([
            'categories' => $categories->map(fn ($c) => (array) $c)->all(),
            'list' => $list,
            'data' => $list,
        ]);
    }

    /**
     * `POST add_ticket_prereply_category`
     */
    public function prereplyCategoryCreate(Request $request)
    {
        $name = trim((string) $request->input('name', $request->input('title', '')));

        if ($name === '') {
            return $this->validationFail('分类名称不能为空');
        }

        $id = (int) DB::table('ticket_prereply_category')->insertGetId([
            'parentid' => (int) $request->input('parentid', 0),
            'name' => $name,
        ]);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST save_ticket_prereply_category`
     */
    public function prereplyCategoryUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $name = trim((string) $request->input('name', $request->input('title', '')));

        if ($id <= 0 || $name === '') {
            return $this->validationFail('参数错误');
        }

        DB::table('ticket_prereply_category')->where('id', $id)->update(['name' => $name]);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE delete_ticket_prereply_category/<id>`
     */
    public function prereplyCategoryDelete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('ticket_prereply')->where('cid', $id)->exists()) {
            return $this->fail('该分类下还有回复，不能删除');
        }

        DB::table('ticket_prereply_category')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET add_ticket_prereply_category/page {id}` and
     * `GET add_ticket_prereply/page {id}`
     */
    public function prereplyCategoryPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('ticket_prereply_category')->where('id', $id)->first() : null;

        if ($id > 0 && $row === null) {
            return $this->fail('ID错误');
        }

        return $this->respond([
            'status' => ApiResponse::OK,
            'mag' => '请求成功',
            'msg' => '请求成功',
            'data' => $row ? (array) $row : null,
            'categories' => DB::table('ticket_prereply_category')->get()->toArray(),
        ] + $this->envelope());
    }

    /**
     * `GET add_ticket_prereply/page` / `GET save_ticket_prereply/page`
     */
    public function prereplyPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('ticket_prereply')->where('id', $id)->first() : null;

        return $this->respond([
            'status' => ApiResponse::OK,
            'mag' => '请求成功',
            'msg' => '请求成功',
            'data' => $row ? (array) $row : null,
            'categories' => DB::table('ticket_prereply_category')->get()->toArray(),
        ] + $this->envelope());
    }

    /**
     * `POST add_ticket_prereply`
     */
    public function prereplyCreate(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('标题不能为空');
        }

        $id = (int) DB::table('ticket_prereply')->insertGetId([
            'cid' => (int) $request->input('cid', 0),
            'title' => $title,
            'content' => (string) $request->input('content', ''),
        ]);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `POST save_ticket_prereply` / `POST ticket_prereply/<id>`
     */
    public function prereplyUpdate(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        $data = [];

        if ($request->has('title')) {
            $data['title'] = (string) $request->input('title');
        }

        if ($request->has('content')) {
            $data['content'] = (string) $request->input('content');
        }

        if ($request->has('cid')) {
            $data['cid'] = (int) $request->input('cid');
        }

        if ($data === []) {
            return $this->validationFail('没有需要保存的内容');
        }

        DB::table('ticket_prereply')->where('id', $id)->update($data);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE ticket_prereply/<id>`
     */
    public function prereplyDelete(Request $request, $id)
    {
        DB::table('ticket_prereply')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 工单自定义字段
    // -----------------------------------------------------------------

    /**
     * `GET add_ticket_custom_param` / `GET edit_ticket_custom_param`
     */
    public function customParamList(Request $request)
    {
        $rows = DB::table('customfields')
            ->where('type', 'ticket')
            ->orderBy('sortorder')
            ->get()
            ->map(fn ($f) => (array) $f)
            ->all();

        return $this->ok([
            'list' => $rows,
            'data' => $rows,
            'type_list' => AdminMeta::CUSTOM_FIELD_TYPES,
        ]);
    }

    /**
     * `POST add_ticket_custom_param` / `POST edit_ticket_custom_param`
     */
    public function customParamCreate(Request $request)
    {
        return $this->writeCustomParam($request, (int) $request->input('id', 0));
    }

    public function customParamUpdate(Request $request)
    {
        return $this->writeCustomParam($request, (int) $request->input('id', 0));
    }

    /**
     * `GET get_custom_param_type`
     */
    public function customParamTypes(Request $request)
    {
        return $this->ok(AdminMeta::CUSTOM_FIELD_TYPES);
    }

    /**
     * `GET get_ticket_param_val {id}` — the values a client submitted.
     */
    public function customParamValues(Request $request)
    {
        $fieldId = (int) $request->input('id', 0);

        if ($fieldId <= 0) {
            return $this->validationFail('自定义字段id不能为空');
        }

        return $this->ok(DB::table('customfieldsvalues')
            ->where('fieldid', $fieldId)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all());
    }

    /**
     * `POST tastes/editUserTanstes` — the ticket list's auto-refresh toggle.
     */
    public function tastes(Request $request)
    {
        $uid = (int) $request->input('uid', $this->adminId());
        $value = (string) $request->input('ticket_refresh', '');

        $exists = DB::table('user_tastes')->where('uid', $uid)->exists();

        if ($exists) {
            DB::table('user_tastes')->where('uid', $uid)->update(['ticket_refresh' => $value]);
        } else {
            DB::table('user_tastes')->insert(['uid' => $uid, 'ticket_refresh' => $value]);
        }

        return $this->ok(['ticket_refresh' => $value], '保存成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function applyFilters($query, Request $request): void
    {
        if ($tid = $request->input('tid')) {
            $query->where('id', (int) $tid);
        }

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        $username = trim((string) $request->input('username', ''));

        if ($username !== '') {
            $uids = Client::query()
                ->where(function ($q) use ($username) {
                    $q->where('username', 'like', "%{$username}%")
                        ->orWhere('email', 'like', "%{$username}%")
                        ->orWhere('companyname', 'like', "%{$username}%");
                })
                ->pluck('id')
                ->all();

            $query->whereIn('uid', $uids ?: [-1]);
        }

        if (($dptid = $request->input('dptid')) !== null && $dptid !== '' && $dptid !== 'ALL') {
            $query->where('dptid', (int) $dptid);
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', (int) $status);
        }

        if (($priority = $request->input('priority')) !== null && $priority !== '' && $priority !== 'ALL') {
            $query->where('priority', $priority);
        }

        if ($content = trim((string) $request->input('content', ''))) {
            $query->where(function ($q) use ($content) {
                $q->where('title', 'like', "%{$content}%")
                    ->orWhere('content', 'like', "%{$content}%");
            });
        }

        if ($handle = $request->input('handle')) {
            $query->where('handle', (int) $handle);
        }
    }

    /**
     * Decorate ticket rows with the joined labels.
     */
    private function decorate($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $uids = $rows->pluck('uid')->unique()->all();
        $clients = Client::query()->whereIn('id', $uids)->get()->keyBy('id');

        $departments = DB::table('ticket_department')->pluck('name', 'id');
        $statuses = TicketStatus::query()->get()->keyBy('id');
        $handlers = DB::table('user')->pluck('user_login', 'id');

        return $rows->map(function (Ticket $ticket) use ($clients, $departments, $statuses, $handlers) {
            $status = $statuses[$ticket->status] ?? null;

            return [
                'id' => (int) $ticket->id,
                'tid' => $ticket->tid,
                'title' => $ticket->title,
                'uid' => (int) $ticket->uid,
                'user_name' => $clients[$ticket->uid]->username ?? $ticket->name,
                'email' => $ticket->email,
                'dptid' => (int) $ticket->dptid,
                'department_name' => (string) ($departments[$ticket->dptid] ?? ''),
                'status' => (int) $ticket->status,
                'status_title' => $status?->title ?? '',
                'status_color' => $status?->color ?? '',
                'priority' => (string) $ticket->priority,
                'handle' => (int) $ticket->handle,
                'handle_name' => (string) ($handlers[$ticket->handle] ?? $ticket->admin ?? ''),
                'create_time' => (int) $ticket->create_time,
                'last_reply_time' => (int) $ticket->last_reply_time,
                'client_unread' => (int) $ticket->client_unread,
                'admin_unread' => (int) $ticket->admin_unread,
                'is_receive' => (int) $ticket->is_receive,
            ];
        })->all();
    }

    /**
     * The default (first) ticket status id.
     */
    private function defaultStatusId(): int
    {
        return (int) (TicketStatus::query()->orderBy('order')->value('id') ?? 1);
    }

    /**
     * The 关闭 status — matched by title because statuses are user-editable.
     */
    private function closedStatusId(): int
    {
        $id = TicketStatus::query()->where('title', '关闭')->value('id');

        return (int) ($id ?? TicketStatus::query()->orderByDesc('id')->value('id') ?? 4);
    }

    private function moveDepartment(Request $request, int $delta)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('ticket_department')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('部门不存在');
        }

        $neighbour = DB::table('ticket_department')
            ->where('order', $delta > 0 ? '>' : '<', $row->order)
            ->orderBy('order', $delta > 0 ? 'asc' : 'desc')
            ->first();

        if ($neighbour === null) {
            return $this->ok(null, '已到边界');
        }

        DB::table('ticket_department')->where('id', $row->id)->update(['order' => $neighbour->order]);
        DB::table('ticket_department')->where('id', $neighbour->id)->update(['order' => $row->order]);

        return $this->ok(null, '操作成功');
    }

    /**
     * The 部门 / 产品 multi-selects of a transfer rule.
     */
    private function syncDeliverRelations(int $id, Request $request): void
    {
        if ($request->has('departments')) {
            DB::table('ticket_deliver_department')->where('tdid', $id)->delete();

            $items = $request->input('departments', []);
            $items = is_array($items) ? $items : explode(',', (string) $items);

            foreach (array_unique(array_filter(array_map('intval', $items))) as $dptid) {
                DB::table('ticket_deliver_department')->insert(['tdid' => $id, 'dptid' => $dptid]);
            }
        }

        if ($request->has('products')) {
            DB::table('ticket_deliver_products')->where('tdid', $id)->delete();

            $items = $request->input('products', []);
            $items = is_array($items) ? $items : explode(',', (string) $items);

            foreach (array_unique(array_filter(array_map('intval', $items))) as $pid) {
                DB::table('ticket_deliver_products')->insert(['tdid' => $id, 'pid' => $pid]);
            }
        }
    }

    /**
     * Create/update a ticket custom field.
     */
    private function writeCustomParam(Request $request, int $id)
    {
        $name = trim((string) $request->input('fieldname', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('字段名称不能为空');
        }

        $data = [
            'type' => 'ticket',
            'fieldname' => $name,
            'fieldtype' => (string) $request->input('fieldtype', 'text'),
            'description' => (string) $request->input('description', ''),
            'fieldoptions' => (string) $request->input('fieldoptions', ''),
            'regexpr' => (string) $request->input('regexpr', ''),
            'required' => (int) $request->input('required', 0),
            'sortorder' => (int) $request->input('sortorder', 0),
            'adminonly' => (int) $request->input('adminonly', 0),
            'showorder' => (int) $request->input('showorder', 0),
            'showinvoice' => (int) $request->input('showinvoice', 0),
            'showdetail' => (int) $request->input('showdetail', 0),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('customfields')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $newId = (int) DB::table('customfields')->insertGetId($data);

        return $this->ok(['id' => $newId], '添加成功');
    }

    /**
     * The 优先级 dictionary.
     */
    private function priorities(): array
    {
        return [
            ['value' => 'low', 'name' => '低'],
            ['value' => 'medium', 'name' => '中'],
            ['value' => 'high', 'name' => '高'],
        ];
    }
}
