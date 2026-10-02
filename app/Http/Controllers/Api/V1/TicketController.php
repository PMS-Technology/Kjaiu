<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;

/**
 * Support tickets.
 *
 * Ticket identifiers are accepted either as the numeric id or as the `tid`
 * token the client area puts in its URLs (`viewticket?tid=…`).
 */
class TicketController extends ApiController
{
    public function __construct(
        protected TicketService $tickets = new TicketService(),
    ) {
    }

    /**
     * GET /v1/tickets — paginated ticket list.
     */
    public function index(Request $request)
    {
        $client = $this->requireClient($request);
        [$page, $limit] = $this->pagination($request);

        $paginator = $this->tickets->paginate($client, $page, $limit, [
            'status' => $request->input('status'),
            'department_id' => $request->input('department_id'),
            'keywords' => $request->input('keywords'),
            'orderby' => $request->input('orderby', 'id'),
            'sort' => $request->input('sort', 'DESC'),
        ]);

        return $this->paginated($paginator, fn (Ticket $ticket) => $this->tickets->listPayload($ticket), [
            'status' => $this->tickets->statuses(),
            'unread' => (int) Ticket::query()
                ->where('uid', $client->id)
                ->where('client_unread', 1)
                ->count(),
        ]);
    }

    /**
     * GET /v1/tickets/page — submit-ticket form data.
     */
    public function page(Request $request)
    {
        $client = $this->requireClient($request);

        return $this->ok($this->tickets->submitPage($request, $client));
    }

    /**
     * POST /v1/tickets — create a ticket.
     */
    public function store(Request $request)
    {
        $client = $this->requireClient($request);

        $departmentId = (int) $request->input('department_id', 0);
        $title = trim((string) $request->input('title', ''));
        $content = trim((string) $request->input('content', ''));

        if ($departmentId <= 0) {
            return $this->fail('请选择工单部门', 406);
        }

        if ($title === '') {
            return $this->fail('请输入工单标题', 406);
        }

        if ($content === '') {
            return $this->fail('请输入工单内容', 406);
        }

        if (mb_strlen($title) > 255) {
            return $this->fail('工单标题过长', 406);
        }

        try {
            $ticket = $this->tickets->create($client, [
                'department_id' => $departmentId,
                'host_id' => (int) $request->input('host_id', 0),
                'title' => $title,
                'content' => $content,
                'priority' => (string) $request->input('priority', 'medium'),
                'custom_fields' => (array) $request->input('custom_fields', $request->input('customfield', [])),
                'attachment' => $request->input('attachment', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok([
            'id' => (int) $ticket->id,
            'tid' => (string) $ticket->tid,
        ], '工单提交成功');
    }

    /**
     * GET /v1/tickets/{id} — ticket detail with replies.
     */
    public function show(Request $request, string $id)
    {
        $client = $this->requireClient($request);
        $ticket = $this->tickets->resolve($client, $id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        $payload = $this->tickets->detailPayload($ticket, true);

        $this->tickets->markClientRead($ticket);

        return $this->ok($payload);
    }

    /**
     * POST /v1/tickets/{id}/reply — add a customer reply.
     */
    public function reply(Request $request, string $id)
    {
        $client = $this->requireClient($request);
        $ticket = $this->tickets->resolve($client, $id);

        if ($ticket === null) {
            return $this->fail('工单不存在');
        }

        $content = trim((string) $request->input('content', ''));

        if ($content === '') {
            return $this->fail('请输入回复内容', 406);
        }

        $reply = $this->tickets->reply(
            $ticket,
            $client,
            $content,
            (array) $request->input('attachment', [])
        );

        return $this->ok([
            'id' => (int) $reply->id,
            'ticket_id' => (int) $ticket->id,
            'create_time' => (int) $reply->create_time,
        ], '回复成功');
    }
}
