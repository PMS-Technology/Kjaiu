<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Support ticket (`shd_ticket`).
 */
class Ticket extends ShdModel
{
    protected $table = 'ticket';

    protected $casts = [
        'uid' => 'integer',
        'dptid' => 'integer',
        'host_id' => 'integer',
        'status' => 'integer',
        'create_time' => 'integer',
        'last_reply_time' => 'integer',
        'client_unread' => 'integer',
        'admin_unread' => 'integer',
        'star' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function department()
    {
        return $this->belongsTo(TicketDepartment::class, 'dptid');
    }

    public function host()
    {
        return $this->belongsTo(Host::class, 'host_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'tid')->orderBy('create_time');
    }

    /**
     * Ticket statuses are administrator-editable rows, not a fixed enum.
     */
    public function statusModel()
    {
        return $this->belongsTo(TicketStatus::class, 'status');
    }

    public function attachmentList(): array
    {
        if (trim((string) $this->attachment) === '') {
            return [];
        }

        $decoded = json_decode((string) $this->attachment, true);

        return is_array($decoded) ? $decoded : [];
    }
}
