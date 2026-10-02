<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\AdminMeta;
use App\Support\PasswordHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 设置 → 员工管理与分组权限 (RBAC), plus the sales commission settings.
 *
 * The permission tree lives in `shd_auth_rule` / `shd_auth_access` and is
 * attached to a role through `shd_role.auth_role` (a comma list of rule ids).
 * `shd_role_user` maps administrators to roles.
 */
class RbacController extends AdminController
{
    /**
     * `GET adminuser` — 员工列表.
     */
    public function adminList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = User::query();

        if ($keywords = trim((string) $request->input('keywords', ''))) {
            $query->where(function ($q) use ($keywords) {
                $q->where('user_login', 'like', "%{$keywords}%")
                    ->orWhere('user_nickname', 'like', "%{$keywords}%")
                    ->orWhere('user_email', 'like', "%{$keywords}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('id')->forPage($page, $limit)->get();

        $roles = DB::table('role')->pluck('name', 'id');
        $roleUsers = DB::table('role_user')->get()->groupBy('user_id');

        $list = $rows->map(function (User $user) use ($roles, $roleUsers) {
            $roleIds = ($roleUsers[$user->id] ?? collect())->pluck('role_id')->all();

            return [
                'id' => (int) $user->id,
                'user_login' => $user->user_login,
                'user_nickname' => $user->user_nickname,
                'user_email' => $user->user_email,
                'mobile' => $user->mobile,
                'language' => $user->language,
                'user_status' => (int) $user->user_status,
                'is_sale' => (int) $user->is_sale,
                'sale_is_use' => (int) $user->sale_is_use,
                'only_mine' => (int) $user->only_mine,
                'cat_ownerless' => (int) $user->cat_ownerless,
                'role_id' => (int) ($roleIds[0] ?? 0),
                'role' => (string) ($roles[$roleIds[0] ?? 0] ?? ''),
                'role_ids' => array_map('intval', $roleIds),
                'dept' => (string) (DB::table('ticket_department_admin')->where('admin_id', $user->id)->value('dptid') ?? ''),
            ];
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'count' => $total,
            'total' => $total,
        ]);
    }

    /**
     * `GET create_page` — 员工新增 form metadata.
     */
    public function adminCreatePage(Request $request)
    {
        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'roles' => DB::table('role')->orderBy('list_order')->get(['id', 'name'])->toArray(),
            'depts' => DB::table('ticket_department')->where('hidden', 0)->get(['id', 'name'])->toArray(),
            'lang' => AdminMeta::LANGUAGES,
        ] + $this->envelope());
    }

    /**
     * `GET adminuser/<id>`
     */
    public function adminDetail(Request $request, $id)
    {
        $user = User::query()->find((int) $id);

        if ($user === null) {
            return $this->notFound('管理员不存在');
        }

        $roleIds = DB::table('role_user')->where('user_id', $user->id)->pluck('role_id')->map(fn ($v) => (int) $v)->all();

        return $this->ok([
            'admin' => [
                'id' => (int) $user->id,
                'user_login' => $user->user_login,
                'user_nickname' => $user->user_nickname,
                'user_email' => $user->user_email,
                'mobile' => $user->mobile,
                'language' => $user->language,
                'user_status' => (int) $user->user_status,
                'is_sale' => (int) $user->is_sale,
                'sale_is_use' => (int) $user->sale_is_use,
                'only_mine' => (int) $user->only_mine,
                'cat_ownerless' => (int) $user->cat_ownerless,
                'role_id' => (int) ($roleIds[0] ?? 0),
                'role_ids' => $roleIds,
            ],
            'roles' => DB::table('role')->get(['id', 'name'])->toArray(),
            'depts' => DB::table('ticket_department')->get(['id', 'name'])->toArray(),
            'lang' => AdminMeta::LANGUAGES,
        ]);
    }

    /**
     * `POST adminuser` — create an administrator.
     */
    public function adminCreate(Request $request)
    {
        $login = trim((string) $request->input('user_login', $request->input('userName', '')));
        $password = (string) $request->input('password', $request->input('pwd', ''));

        if ($login === '') {
            return $this->validationFail('用户名不能为空');
        }

        if ($password === '') {
            return $this->validationFail('密码不能为空');
        }

        if (mb_strlen($password) > 64) {
            return $this->validationFail('密码长度不能超过64位');
        }

        if (User::query()->where('user_login', $login)->exists()) {
            return $this->validationFail('该用户名已存在');
        }

        $plain = PasswordHasher::acceptedPlain($password);

        $user = User::query()->create([
            'user_login' => $login,
            'user_pass' => PasswordHasher::admin($plain),
            'user_nickname' => (string) $request->input('user_nickname', $request->input('realName', '')),
            'user_email' => (string) $request->input('user_email', $request->input('email', '')),
            'mobile' => (string) $request->input('mobile', ''),
            'language' => (string) $request->input('language', 'zh-cn'),
            'user_status' => (int) $request->input('user_status', 1),
            'is_sale' => (int) $request->input('is_sale', 0),
            'sale_is_use' => (int) $request->input('sale_is_use', 0),
            'only_mine' => (int) $request->input('only_mine', 0),
            'cat_ownerless' => (int) $request->input('cat_ownerless', 0),
            'create_time' => time(),
        ]);

        $this->syncRoles((int) $user->id, $request);
        $this->syncDepartments((int) $user->id, $request);

        $this->log('添加管理员：'.$login, (int) $user->id);

        return $this->ok(['id' => (int) $user->id], '添加成功');
    }

    /**
     * `POST adminuser/update` — edit an administrator.
     */
    public function adminUpdate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $user = User::query()->find($id);

        if ($user === null) {
            return $this->notFound('管理员不存在');
        }

        $data = [];

        foreach (['user_nickname', 'user_email', 'mobile', 'language'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        foreach (['user_status', 'is_sale', 'sale_is_use', 'only_mine', 'cat_ownerless'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (int) $request->input($field);
            }
        }

        // The primary administrator's login name is immutable.
        if ((int) $user->id !== 1 && $request->filled('user_login')) {
            $login = trim((string) $request->input('user_login'));

            if ($login !== '' && $login !== $user->user_login && User::query()->where('user_login', $login)->exists()) {
                return $this->validationFail('该用户名已存在');
            }

            $data['user_login'] = $login;
        }

        $password = (string) $request->input('password', $request->input('pwd', ''));

        if ($password !== '') {
            $data['user_pass'] = PasswordHasher::admin(PasswordHasher::acceptedPlain($password));
        }

        if ($data !== []) {
            $user->fill($data)->save();
        }

        $this->syncRoles($id, $request);
        $this->syncDepartments($id, $request);

        $this->log('编辑管理员 #'.$id, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE adminuser/<id>`
     */
    public function adminDelete(Request $request, $id)
    {
        $id = (int) $id;

        if ($id === 1) {
            return $this->fail('超级管理员不能删除');
        }

        if ($id === $this->adminId()) {
            return $this->fail('不能删除当前登录的管理员');
        }

        User::query()->whereKey($id)->delete();
        DB::table('role_user')->where('user_id', $id)->delete();
        DB::table('ticket_department_admin')->where('admin_id', $id)->delete();

        $this->log('删除管理员 #'.$id);

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST user/edit_self_info` — the header's 个人资料 dialog.
     */
    public function editSelfInfo(Request $request)
    {
        $user = $this->admin();

        if ($user === null) {
            return $this->fail('请先登录', 401);
        }

        $data = [];

        foreach (['user_nickname', 'user_email', 'mobile', 'avatar', 'language'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        $newPassword = (string) $request->input('new_password', $request->input('password', ''));

        if ($newPassword !== '') {
            $old = (string) $request->input('old_password', '');

            if (! PasswordHasher::checkAdmin(PasswordHasher::acceptedPlain($old), $user->user_pass)) {
                return $this->validationFail('原密码错误');
            }

            $data['user_pass'] = PasswordHasher::admin(PasswordHasher::acceptedPlain($newPassword));
        }

        if ($data !== []) {
            $user->fill($data)->save();
        }

        $this->log('修改个人资料');

        return $this->ok(null, '保存成功');
    }

    // -----------------------------------------------------------------
    // 分组权限
    // -----------------------------------------------------------------

    /**
     * `GET rbac` — 分组权限列表.
     */
    public function roleList(Request $request)
    {
        $roles = DB::table('role')->orderBy('list_order')->get();

        $list = $roles->map(function ($role) {
            $userIds = DB::table('role_user')->where('role_id', $role->id)->pluck('user_id')->all();
            $logins = $userIds === []
                ? []
                : User::query()->whereIn('id', $userIds)->pluck('user_login')->all();

            return [
                'id' => (int) $role->id,
                'name' => $role->name,
                'remark' => $role->remark,
                'status' => (int) $role->status,
                'parent_id' => (int) $role->parent_id,
                'user_login' => implode(',', $logins),
                'user_count' => count($userIds),
            ];
        })->all();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'roles' => $list,
            'list' => $list,
        ] + $this->envelope());
    }

    /**
     * `GET rbac/role_page` — the permission tree for the editor.
     */
    public function rolePage(Request $request)
    {
        $rules = DB::table('auth_rule')
            ->orderBy('app')
            ->orderBy('order')
            ->get(['id', 'pid', 'title', 'name', 'app', 'type', 'status', 'is_display']);

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $rules->map(fn ($r) => (array) $r)->all(),
            'rules' => $rules->map(fn ($r) => (array) $r)->all(),
            'roles' => DB::table('role')->get(['id', 'name'])->toArray(),
        ] + $this->envelope());
    }

    /**
     * `GET rbac/<id>` — one role with its permission ids and members.
     */
    public function roleDetail(Request $request, $id)
    {
        $role = DB::table('role')->where('id', (int) $id)->first();

        if ($role === null) {
            return $this->notFound('分组不存在');
        }

        $authRole = trim((string) $role->auth_role, ',');
        $ruleIds = $authRole === '' ? [] : array_map('intval', explode(',', $authRole));

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'remark' => $role->remark,
                'status' => (int) $role->status,
                'auth_role' => $ruleIds,
                'user' => DB::table('role_user')->where('role_id', $role->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all(),
            ],
            'rules' => DB::table('auth_rule')->get(['id', 'pid', 'title', 'name', 'app'])->toArray(),
            'users' => User::query()->where('user_status', 1)->get(['id', 'user_login', 'user_nickname'])->toArray(),
        ] + $this->envelope());
    }

    /**
     * `POST rbac`
     */
    public function roleCreate(Request $request)
    {
        return $this->writeRole($request, 0);
    }

    /**
     * `POST rbac/edit`
     */
    public function roleUpdate(Request $request)
    {
        return $this->writeRole($request, (int) $request->input('id', 0));
    }

    /**
     * `POST rbac/copyRole` — duplicate a role and its permissions.
     */
    public function roleCopy(Request $request)
    {
        $sourceId = (int) $request->input('role_id', 0);
        $name = trim((string) $request->input('role_name', ''));

        $source = DB::table('role')->where('id', $sourceId)->first();

        if ($source === null) {
            return $this->notFound('原分组不存在');
        }

        if ($name === '') {
            return $this->validationFail('新分组名称不能为空');
        }

        $id = (int) DB::table('role')->insertGetId([
            'parent_id' => $source->parent_id,
            'status' => $source->status,
            'create_time' => time(),
            'update_time' => time(),
            'list_order' => $source->list_order,
            'name' => $name,
            'remark' => (string) ($request->input('role_remark') ?: $source->remark),
            'auth_role' => $source->auth_role,
        ]);

        $this->log('复制权限分组：'.$source->name.' → '.$name, $id);

        return $this->ok(['id' => $id], '复制成功');
    }

    /**
     * `DELETE rbac/<id>`
     */
    public function roleDelete(Request $request, $id)
    {
        $id = (int) $id;

        if ($id === 1) {
            return $this->fail('超级管理员分组不能删除');
        }

        if (DB::table('role_user')->where('role_id', $id)->exists()) {
            return $this->fail('该分组下还有管理员，不能删除');
        }

        DB::table('role')->where('id', $id)->delete();
        DB::table('auth_access')->where('role_id', $id)->delete();

        $this->log('删除权限分组 #'.$id);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET system/systemAuthRuleLanguage` — the permission tree with its
     * language map resolved.
     */
    public function authRuleLanguage(Request $request)
    {
        $rows = DB::table('auth_rule')->orderBy('order')->get();

        return $this->ok($rows->map(function ($rule) {
            $row = (array) $rule;
            $map = json_decode((string) $rule->language_map, true);
            $row['language'] = is_array($map) ? $map : [];

            return $row;
        })->all());
    }

    // -----------------------------------------------------------------
    // 销售设置
    // -----------------------------------------------------------------

    /**
     * `GET sale/adminlist` — the salespeople list.
     */
    public function saleAdminList(Request $request)
    {
        $rows = User::query()
            ->where('user_status', 1)
            ->get(['id', 'user_login', 'user_nickname', 'user_email', 'is_sale', 'sale_is_use', 'only_mine', 'cat_ownerless']);

        $roles = DB::table('role')->pluck('name', 'id');
        $roleUsers = DB::table('role_user')->get()->groupBy('user_id');

        return $this->ok($rows->map(function (User $user) use ($roles, $roleUsers) {
            $roleId = (int) (($roleUsers[$user->id] ?? collect())->pluck('role_id')->first() ?? 0);

            return [
                'id' => (int) $user->id,
                'user_login' => $user->user_login,
                'user_nickname' => $user->user_nickname,
                'user_email' => $user->user_email,
                'role' => (string) ($roles[$roleId] ?? ''),
                'role_id' => $roleId,
                'is_sale' => (int) $user->is_sale,
                'sale_is_use' => (int) $user->sale_is_use,
                'only_mine' => (int) $user->only_mine,
                'cat_ownerless' => (int) $user->cat_ownerless,
            ];
        })->all());
    }

    /**
     * `GET salegroup` — 销售分组.
     */
    public function saleGroupList(Request $request)
    {
        $rows = DB::table('sales_product_groups')->orderByDesc('id')->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['pids'] = $entry['pids'] === null || $entry['pids'] === ''
                ? []
                : array_map('intval', explode(',', trim((string) $entry['pids'], ',')));

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => count($list),
            'products' => AdminMeta::productList(),
        ]);
    }

    /**
     * `GET sale/add_salegrouppage` / `GET sale/edit_salegrouppage`
     */
    public function saleGroupPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('sales_product_groups')->where('id', $id)->first() : null;

        return $this->ok([
            'group' => $row ? (array) $row : null,
            'products' => AdminMeta::productList(),
        ]);
    }

    /**
     * `POST salegroup`
     */
    public function saleGroupSave(Request $request)
    {
        $name = trim((string) $request->input('group_name', ''));

        if ($name === '') {
            return $this->validationFail('组名称不能为空');
        }

        $pids = $request->input('pids', []);
        $pids = is_array($pids) ? $pids : explode(',', (string) $pids);

        $data = [
            'group_name' => $name,
            'bates' => (float) $request->input('bates', 0),
            'is_renew' => (int) $request->input('is_renew', 0),
            'updategrade' => (int) $request->input('updategrade', 0),
            'pids' => implode(',', array_unique(array_filter(array_map('intval', $pids)))),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('sales_product_groups')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $id = (int) DB::table('sales_product_groups')->insertGetId($data);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `DELETE salegroup/<id>`
     */
    public function saleGroupDelete(Request $request, $id)
    {
        DB::table('sales_product_groups')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET saleladder` — 销售阶梯.
     */
    public function saleLadderList(Request $request)
    {
        $rows = DB::table('sale_ladder')->orderByDesc('turnover')->get();

        return $this->okFlat('请求成功', [
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $rows->count(),
        ]);
    }

    /**
     * `GET sale/edit_saleladderpage`
     */
    public function saleLadderPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('sale_ladder')->where('id', $id)->first() : null;

        return $this->ok(['ladder' => $row ? (array) $row : null]);
    }

    /**
     * `POST saleladder`
     */
    public function saleLadderSave(Request $request)
    {
        $turnover = (float) $request->input('turnover', 0);

        if ($turnover <= 0) {
            return $this->validationFail('营业额必须大于0');
        }

        $data = [
            'turnover' => $this->money($turnover),
            'bates' => (float) $request->input('bates', 0),
            'is_flag' => (int) $request->input('is_flag', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('sale_ladder')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('sale_ladder')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE saleladder/<id>`
     */
    public function saleLadderDelete(Request $request, $id)
    {
        DB::table('sale_ladder')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET sale/edit_delproduct` — the products a salesperson may sell.
     */
    public function saleProducts(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->ok([
            'products' => AdminMeta::productList(),
            'selected' => DB::table('sale_products')
                ->when($uid > 0, fn ($q) => $q->where('uid', $uid))
                ->pluck('pid')
                ->map(fn ($v) => (int) $v)
                ->all(),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Create or update one `shd_role` row with its permissions and members.
     */
    private function writeRole(Request $request, int $id)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('分组名称不能为空');
        }

        $auth = $request->input('auth_role', $request->input('auth', []));

        if (is_array($auth)) {
            $auth = implode(',', array_unique(array_filter(array_map('intval', $auth))));
        }

        $data = [
            'name' => $name,
            'remark' => (string) $request->input('remark', ''),
            'status' => (int) $request->input('status', 0),
            'auth_role' => (string) $auth,
            'update_time' => time(),
        ];

        if ($request->filled('parent_id')) {
            $data['parent_id'] = (int) $request->input('parent_id');
        }

        if ($id > 0) {
            // The super-administrator role keeps its full permission set.
            if ($id === 1) {
                unset($data['auth_role']);
            }

            DB::table('role')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            $data['list_order'] = (float) (DB::table('role')->max('list_order') ?? 0) + 1;
            $id = (int) DB::table('role')->insertGetId($data);
        }

        // Members: the multi-select posts administrator ids.
        if ($request->has('user')) {
            $users = $request->input('user', []);
            $users = is_array($users) ? $users : explode(',', (string) $users);
            $users = array_unique(array_filter(array_map('intval', $users)));

            DB::table('role_user')->where('role_id', $id)->delete();

            foreach ($users as $userId) {
                DB::table('role_user')->insert(['role_id' => $id, 'user_id' => $userId]);
            }
        }

        // `shd_auth_access` mirrors the rule list for the framework's checks.
        if ($request->has('auth_role') || $request->has('auth')) {
            DB::table('auth_access')->where('role_id', $id)->delete();

            foreach (explode(',', (string) $data['auth_role']) as $ruleId) {
                $ruleId = (int) trim($ruleId);

                if ($ruleId > 0) {
                    DB::table('auth_access')->insert([
                        'role_id' => $id,
                        'rule_name' => (string) (DB::table('auth_rule')->where('id', $ruleId)->value('name') ?? ''),
                        'type' => 'admin_url',
                        'rule_id' => $ruleId,
                    ]);
                }
            }
        }

        $this->log(($id > 0 ? '保存' : '添加').'权限分组：'.$name, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * Replace an administrator's role assignments.
     */
    private function syncRoles(int $userId, Request $request): void
    {
        $roles = $request->input('role_id', $request->input('role_ids'));

        if ($roles === null) {
            return;
        }

        $roles = is_array($roles) ? $roles : [$roles];
        $roles = array_unique(array_filter(array_map('intval', $roles)));

        DB::table('role_user')->where('user_id', $userId)->delete();

        foreach ($roles as $roleId) {
            DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        }
    }

    /**
     * Replace an administrator's ticket departments.
     */
    private function syncDepartments(int $userId, Request $request): void
    {
        $depts = $request->input('dept', $request->input('depts'));

        if ($depts === null) {
            return;
        }

        $depts = is_array($depts) ? $depts : [$depts];
        $depts = array_unique(array_filter(array_map('intval', $depts)));

        DB::table('ticket_department_admin')->where('admin_id', $userId)->delete();

        foreach ($depts as $dptid) {
            DB::table('ticket_department_admin')->insert(['admin_id' => $userId, 'dptid' => $dptid]);
        }
    }
}
