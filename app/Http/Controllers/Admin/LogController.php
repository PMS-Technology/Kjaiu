<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Services\Admin\AdminMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 设置 → 日志 — 系统日志 / 操作日志 / 短信日志 / 邮件日志 / API日志 /
 * 定时任务日志 / 站内信日志.
 *
 * Every log table shares the same shape (id, create_time, description, user,
 * ipaddr), so they all funnel through one list builder.
 */
class LogController extends AdminController
{
    /** Log source => table + the columns shown. */
    private const SOURCES = [
        'systemlog' => 'activity_log',
        'adminlog' => 'admin_log',
        'smslog' => 'message_log',
        'emaillog' => 'email_log',
        'cronsystemlog' => 'cron_log',
        'systemmessagelog' => 'system_message',
        'notifylog' => 'system_log',
        'userlog' => 'activity_log_home',
    ];

    /**
     * `GET log_record/systemlog` / `GET log_record`
     */
    public function systemLog(Request $request)
    {
        return $this->logList($request, 'systemlog');
    }

    /**
     * `GET log_record/adminlog` — administrator sessions (`shd_admin_log`).
     */
    public function adminLog(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('admin_log');

        if ($username = trim((string) $request->input('username', $request->input('keywords', '')))) {
            $query->where('admin_username', 'like', "%{$username}%");
        }

        if ($start = $this->timestamp($request->input('start_time'))) {
            $query->where('logintime', '>=', $start);
        }

        if ($end = $this->timestamp($request->input('end_time'))) {
            $query->where('logintime', '<=', $end);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'admin_username' => $row->admin_username,
            'username' => $row->admin_username,
            'logintime' => (int) $row->logintime,
            'logouttime' => (int) $row->logouttime,
            'create_time' => (int) $row->logintime,
            'lastvisit' => (int) $row->lastvisit,
            'ipaddress' => $row->ipaddress,
            'ipaddr' => $row->ipaddress,
            'port' => $row->port,
            'sessionid' => $row->sessionid,
            'new_desc' => '登录后台',
        ])->all();

        return $this->flat($list, $total, $page, $limit);
    }

    /**
     * `GET log_record/smslog` (`shd_message_log`)
     */
    public function smsLog(Request $request)
    {
        return $this->logList($request, 'smslog');
    }

    /**
     * `GET log_record/emaillog` (`shd_email_log`)
     */
    public function emailLog(Request $request)
    {
        return $this->logList($request, 'emaillog');
    }

    /**
     * `GET log_record/api_log` (`shd_api_resource_log`)
     */
    public function apiLog(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('api_resource_log');

        if ($description = trim((string) $request->input('description', $request->input('keywords', '')))) {
            $query->where('description', 'like', "%{$description}%");
        }

        if ($ip = trim((string) $request->input('ip', ''))) {
            $query->where('ip', 'like', "%{$ip}%");
        }

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        if ($start = $this->timestamp($request->input('start_time'))) {
            $query->where('create_time', '>=', $start);
        }

        if ($end = $this->timestamp($request->input('end_time'))) {
            $query->where('create_time', '<=', $end);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'uid' => (int) $row->uid,
            'pid' => (int) $row->pid,
            'version' => $row->version,
            'new_desc' => $row->description,
            'description' => $row->description,
            'ip' => $row->ip,
            'ipaddr' => $row->ip,
            'port' => $row->port,
            'source' => $row->source,
            'create_time' => (int) $row->create_time,
            'username' => (string) (Client::query()->whereKey($row->uid)->value('username') ?? ''),
        ])->all();

        return $this->flat($list, $total, $page, $limit);
    }

    /**
     * `GET log_record/cronsystemlog` (`shd_cron_log`)
     */
    public function cronLog(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('cron_log');

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")->orWhere('method', 'like', "%{$name}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'method' => $row->method,
            'value' => $row->value,
            'new_desc' => $row->name.' → '.$row->method,
            'create_time' => (int) $row->create_time,
            'update_time' => (int) $row->update_time,
        ])->all();

        return $this->flat($list, $total, $page, $limit);
    }

    /**
     * `GET log_record/systemmessagelog` (`shd_system_message`)
     */
    public function messageLog(Request $request)
    {
        return $this->logList($request, 'systemmessagelog');
    }

    /**
     * `GET log_record/notifylog` (`shd_system_log`)
     */
    public function notifyLog(Request $request)
    {
        return $this->logList($request, 'notifylog');
    }

    /**
     * `GET log_record/userlog` — client-area activity (`shd_activity_log_home`).
     */
    public function userLog(Request $request)
    {
        return $this->logList($request, 'userlog');
    }

    /**
     * `DELETE log_record/delete_log {type, time}` — prune old log rows.
     *
     * `time` is the number of days to keep; the original offers preset
     * windows (7 / 30 / 90 days) in the 日志清理 dialog.
     */
    public function deleteLog(Request $request)
    {
        $type = (string) $request->input('type', 'systemlog');
        $days = (int) $request->input('time', $request->input('day', 0));

        $tables = $type === 'all'
            ? array_values(self::SOURCES)
            : [self::SOURCES[$type] ?? 'activity_log'];

        $cutoff = $days > 0 ? strtotime('-'.$days.' days') : time();

        $deleted = 0;

        foreach (array_unique($tables) as $table) {
            if ($table === 'admin_log') {
                $deleted += DB::table($table)->where('logintime', '<', $cutoff)->delete();

                continue;
            }

            if (! $this->hasColumn($table, 'create_time')) {
                continue;
            }

            $deleted += DB::table($table)->where('create_time', '<', $cutoff)->delete();
        }

        $this->log('清理日志：'.$type.' 保留 '.$days.' 天');

        return $this->ok(['deleted' => $deleted], '清理成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Generic log list for the uniform tables.
     */
    private function logList(Request $request, string $source)
    {
        $table = self::SOURCES[$source];

        [$page, $limit] = $this->pageParams($request);

        $query = DB::table($table);

        $keyword = trim((string) $request->input('keywords', $request->input('description', '')));

        if ($keyword !== '') {
            $query->where(function ($q) use ($table, $keyword) {
                if ($this->hasColumn($table, 'description')) {
                    $q->where('description', 'like', "%{$keyword}%");
                }

                if ($this->hasColumn($table, 'username')) {
                    $q->orWhere('username', 'like', "%{$keyword}%");
                }

                if ($this->hasColumn($table, 'user')) {
                    $q->orWhere('user', 'like', "%{$keyword}%");
                }
            });
        }

        if ($username = trim((string) $request->input('username', ''))) {
            if ($this->hasColumn($table, 'user')) {
                $query->where('user', 'like', "%{$username}%");
            }
        }

        if ($ip = trim((string) $request->input('ip', ''))) {
            foreach (['ipaddr', 'ip'] as $column) {
                if ($this->hasColumn($table, $column)) {
                    $query->where($column, 'like', "%{$ip}%");

                    break;
                }
            }
        }

        if ($start = $this->timestamp($request->input('start_time'))) {
            if ($this->hasColumn($table, 'create_time')) {
                $query->where('create_time', '>=', $start);
            }
        }

        if ($end = $this->timestamp($request->input('end_time'))) {
            if ($this->hasColumn($table, 'create_time')) {
                $query->where('create_time', '<=', $end);
            }
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $uids = [];
        $list = [];

        foreach ($rows as $row) {
            $entry = (array) $row;
            // Normalise the column names the log tables render.
            $entry['new_desc'] = (string) ($row->description ?? $row->content ?? $row->message ?? $row->subject ?? '');
            $entry['description'] = $entry['new_desc'];
            $entry['username'] = (string) ($row->user ?? $row->username ?? $row->to ?? $row->title ?? '');
            $entry['user'] = $entry['username'];
            $entry['ipaddr'] = (string) ($row->ipaddr ?? $row->ip ?? '');
            $entry['ip'] = $entry['ipaddr'];
            $entry['create_time'] = (int) ($row->create_time ?? $row->logintime ?? 0);

            if (isset($row->uid)) {
                $uids[] = (int) $row->uid;
            }

            $list[] = $entry;
        }

        // Resolve client names in one query rather than per row.
        if ($uids !== []) {
            $names = Client::query()->whereIn('id', array_unique($uids))->pluck('username', 'id');

            foreach ($list as $index => $entry) {
                if ($entry['username'] === '' && isset($entry['uid'])) {
                    $list[$index]['username'] = (string) ($names[$entry['uid']] ?? '');
                    $list[$index]['user'] = $list[$index]['username'];
                }
            }
        }

        return $this->flat($list, $total, $page, $limit, [
            'type' => $source,
            'identify' => '/' . trim((string) config('kjaiu.admin_path', 'admin'), '/') . '/log_record/' . $source,
        ]);
    }

    /**
     * Log lists carry their rows under `data` *and* `list`.
     */
    private function flat(array $list, int $total, int $page, int $limit, array $extra = [])
    {
        return $this->okFlat('请求成功', array_merge([
            'data' => $list,
            'list' => $list,
            'total' => $total,
            'count' => $total,
            'page' => $page,
            'limit' => $limit,
        ], $extra));
    }

    /**
     * Cached column lookup, used to tolerate the differing log table shapes.
     */
    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];

        if (! isset($cache[$table])) {
            $cache[$table] = DB::getSchemaBuilder()->getColumnListing($table);
        }

        return in_array($column, $cache[$table], true);
    }
}
