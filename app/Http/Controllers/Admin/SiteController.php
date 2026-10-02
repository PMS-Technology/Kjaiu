<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AdminMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 站务设置 — 导航管理, 友情链接, 新闻中心/帮助中心, 知识库, 文件下载,
 * 黑名单 and the system-information pages.
 */
class SiteController extends AdminController
{
    // -----------------------------------------------------------------
    // 导航 / 菜单
    // -----------------------------------------------------------------

    /**
     * `GET menus/allLinks` — every navigable link the menu builder can use.
     */
    public function menuLinks(Request $request)
    {
        $nav = DB::table('nav')->orderBy('order')->get();
        $groups = DB::table('nav_group')->orderBy('order')->get();
        $menus = DB::table('menus')->orderBy('sort')->get();

        return $this->ok([
            'nav' => $nav->map(fn ($n) => (array) $n)->all(),
            'nav_group' => $groups->map(fn ($g) => (array) $g)->all(),
            'menus' => $menus->map(fn ($m) => (array) $m)->all(),
            'links' => $this->linkCatalogue(),
        ]);
    }

    /**
     * `GET menus/getCreateWebData` — metadata for the menu builder.
     */
    public function menuCreateData(Request $request)
    {
        return $this->ok([
            'nav' => DB::table('nav')->where('nav_type', 1)->orderBy('order')->get()->toArray(),
            'nav_group' => DB::table('nav_group')->orderBy('order')->get()->toArray(),
            'lang' => AdminMeta::LANGUAGES,
            'type' => [1 => '自定义链接', 2 => '商品分类', 3 => '单页'],
        ]);
    }

    /**
     * `GET menu_setting_page` / `GET menu_position_page`
     */
    public function menuSettingPage(Request $request)
    {
        return $this->menuCreateData($request);
    }

    public function menuPositionPage(Request $request)
    {
        return $this->ok([
            'position' => [
                ['value' => 'header', 'name' => '顶部导航'],
                ['value' => 'footer', 'name' => '底部导航'],
                ['value' => 'sidebar', 'name' => '侧边栏'],
            ],
            'menus' => DB::table('menus')->orderBy('sort')->get()->toArray(),
        ]);
    }

    /**
     * `POST menus/save` — create or update a `shd_nav` entry.
     */
    public function menuSave(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('菜单名称不能为空');
        }

        $data = [
            'name' => $name,
            'url' => (string) $request->input('url', ''),
            'pid' => (int) $request->input('pid', 0),
            'order' => (int) $request->input('order', 0),
            'fa_icon' => (string) $request->input('fa_icon', ''),
            'nav_type' => (int) $request->input('nav_type', 1),
            'menuid' => (int) $request->input('menuid', 0),
            'menu_type' => (int) $request->input('menu_type', 1),
            'plugin' => (string) $request->input('plugin', ''),
            'relid' => is_array($request->input('relid'))
                ? implode(',', array_filter(array_map('intval', $request->input('relid'))))
                : (string) $request->input('relid', ''),
            'lang' => is_array($request->input('lang'))
                ? json_encode($request->input('lang'), JSON_UNESCAPED_UNICODE)
                : (string) $request->input('lang', ''),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('nav')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('nav')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE menus/<id>`
     */
    public function menuDelete(Request $request, $id)
    {
        DB::table('nav')->where('pid', (int) $id)->delete();
        DB::table('nav')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET nav/list`
     */
    public function navList(Request $request)
    {
        return $this->ok(DB::table('nav')->orderBy('order')->get()->map(fn ($n) => (array) $n)->all());
    }

    /**
     * `POST nav/save`
     */
    public function navSave(Request $request)
    {
        return $this->menuSave($request);
    }

    /**
     * `DELETE nav/<id>`
     */
    public function navDelete(Request $request, $id)
    {
        return $this->menuDelete($request, $id);
    }

    /**
     * `GET nav_group/list`
     */
    public function navGroupList(Request $request)
    {
        $rows = DB::table('nav_group')->orderBy('order')->get();

        return $this->ok($rows->map(function ($group) {
            $row = (array) $group;
            $row['children'] = DB::table('nav')->where('menuid', $group->id)->orderBy('order')->get()->map(fn ($n) => (array) $n)->all();

            return $row;
        })->all());
    }

    /**
     * `POST nav_group/save`
     */
    public function navGroupSave(Request $request)
    {
        $name = trim((string) $request->input('groupname', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('分组名称不能为空');
        }

        $data = [
            'groupname' => $name,
            'fa_icon' => (string) $request->input('fa_icon', ''),
            'order' => (int) $request->input('order', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('nav_group')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('nav_group')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE nav_group/<id>`
     */
    public function navGroupDelete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('nav')->where('menuid', $id)->exists()) {
            return $this->fail('该分组下还有菜单，不能删除');
        }

        DB::table('nav_group')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 友情链接
    // -----------------------------------------------------------------

    /**
     * `GET friendly_link/list` / `GET link_cause/list`
     */
    public function friendlyLinkList(Request $request)
    {
        $rows = DB::table('friendly_links')->orderBy('id')->get();

        return $this->ok($rows->map(fn ($r) => (array) $r)->all());
    }

    /**
     * `POST friendly_link/save` / `POST link_cause/create|edit`
     */
    public function friendlyLinkSave(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('名称不能为空');
        }

        $data = [
            'name' => $name,
            'domain' => (string) $request->input('domain', ''),
            'link_tag' => (string) $request->input('link_tag', ''),
            'is_open' => (int) $request->input('is_open', 1),
            'update_time' => time(),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('friendly_links')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();

        return $this->ok(['id' => (int) DB::table('friendly_links')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE friendly_link/<id>` / `DELETE link_cause/<id>`
     */
    public function friendlyLinkDelete(Request $request, $id)
    {
        DB::table('friendly_links')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 新闻中心 / 帮助中心
    // -----------------------------------------------------------------

    /**
     * `GET news/list` — `shd_news` holds content keyed by `relid` and the
     * article rows live in `shd_news_menu`; the list joins them.
     */
    public function newsList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('news_menu')
            ->leftJoin('news_type as t', 't.id', '=', 'news_menu.parent_id')
            ->leftJoin('news as n', 'n.relid', '=', 'news_menu.id')
            ->select('news_menu.id', 'news_menu.admin_id', 'news_menu.parent_id', 'news_menu.title', 'news_menu.keywords', 'news_menu.description', 'news_menu.head_img', 'news_menu.read', 'news_menu.hidden', 'news_menu.sort', 'news_menu.update_time', 'news_menu.create_time', 'news_menu.push_time', 'news_menu.label', 't.title as type_title', 'n.content');

        if ($parentId = $request->input('parent_id', $request->input('type_id'))) {
            $query->where('news_menu.parent_id', (int) $parentId);
        }

        if ($title = trim((string) $request->input('title', $request->input('keywords', '')))) {
            $query->where('news_menu.title', 'like', "%{$title}%");
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('news_menu.sort', $request->input('sort', 'DESC') === 'ASC' ? 'asc' : 'desc')
            ->forPage($page, $limit)
            ->get();

        $list = $rows->map(fn ($r) => (array) $r)->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'type' => DB::table('news_type')->orderBy('id')->get()->map(fn ($t) => (array) $t)->all(),
        ]);
    }

    /**
     * `POST news/save` (also used by 帮助中心)
     */
    public function newsSave(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('标题不能为空');
        }

        $id = (int) $request->input('id', 0);

        // `shd_news_menu` holds the article metadata; the body lives in
        // `shd_news` keyed by `relid`.
        $menu = [
            'title' => $title,
            'parent_id' => (int) $request->input('parent_id', $request->input('type_id', 0)),
            'keywords' => (string) $request->input('keywords', ''),
            'description' => (string) $request->input('description', ''),
            'head_img' => (string) $request->input('head_img', ''),
            'label' => (string) $request->input('label', ''),
            'read' => (int) $request->input('read', 0),
            'hidden' => (int) $request->input('hidden', 0),
            'sort' => (int) $request->input('sort', $request->input('sorting', 0)),
            'admin_id' => $this->adminId(),
            'create_time' => time(),
        ];

        if ($id > 0) {
            $menu['update_time'] = time();
            DB::table('news_menu')->where('id', $id)->update($menu);
        } else {
            $id = (int) DB::table('news_menu')->insertGetId($menu);
        }

        $content = (string) $request->input('content', '');
        $exists = DB::table('news')->where('relid', $id)->exists();

        if ($exists) {
            DB::table('news')->where('relid', $id)->update(['content' => $content]);
        } else {
            DB::table('news')->insert(['relid' => $id, 'content' => $content]);
        }

        $this->log('保存新闻/帮助：'.$title, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE news/content {id}`
     */
    public function newsDelete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('news_menu')->whereIn('id', $ids)->delete();
        DB::table('news')->whereIn('relid', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    /**
     * `GET news/type`
     */
    public function newsTypeList(Request $request)
    {
        return $this->ok(DB::table('news_type')->orderBy('id')->get()->map(fn ($t) => (array) $t)->all());
    }

    /**
     * `POST news/type`
     */
    public function newsTypeSave(Request $request)
    {
        $title = trim((string) $request->input('title', $request->input('name', '')));

        if ($title === '') {
            return $this->validationFail('分类名称不能为空');
        }

        $data = [
            'title' => $title,
            'sort' => (int) $request->input('sort', 0),
            'hidden' => (int) $request->input('hidden', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('news_type')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('news_type')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE news/type/<id>`
     */
    public function newsTypeDelete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('news_menu')->where('parent_id', $id)->exists()) {
            return $this->fail('该分类下还有内容，不能删除');
        }

        DB::table('news_type')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 知识库
    // -----------------------------------------------------------------

    /**
     * `GET knowledge_base/index`
     */
    public function knowledgeList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('knowledge_base');

        if ($title = trim((string) $request->input('title', $request->input('keywords', '')))) {
            $query->where('title', 'like', "%{$title}%");
        }

        if ($cat = $request->input('cat_id', $request->input('category_id'))) {
            $query->whereIn('id', DB::table('knowledge_base_links')->where('category_id', (int) $cat)->pluck('article_id')->all() ?: [-1]);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $total,
            'count' => $total,
            'cats' => DB::table('knowledge_base_cats')->orderBy('id')->get()->map(fn ($c) => (array) $c)->all(),
        ]);
    }

    /**
     * `POST knowledge_base/save`
     */
    public function knowledgeSave(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('标题不能为空');
        }

        $data = [
            'title' => $title,
            'article' => (string) $request->input('article', $request->input('content', '')),
            'hidden' => (int) $request->input('hidden', 0),
            'login_view' => (int) $request->input('login_view', 0),
            'host_view' => (int) $request->input('host_view', 0),
            'order' => (int) $request->input('order', 0),
            'create_by' => $this->adminId(),
            'public_by' => $this->adminName(),
            'public_time' => date('Y-m-d H:i:s'),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('knowledge_base')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            $data['views'] = 0;
            $data['useful'] = 0;
            $id = (int) DB::table('knowledge_base')->insertGetId($data);
        }

        if ($request->has('cat_id')) {
            DB::table('knowledge_base_links')->where('article_id', $id)->delete();

            $cats = $request->input('cat_id', $request->input('category_id'));
            $cats = is_array($cats) ? $cats : [$cats];

            foreach (array_unique(array_filter(array_map('intval', (array) $cats))) as $catId) {
                DB::table('knowledge_base_links')->insert(['article_id' => $id, 'category_id' => $catId]);
            }
        }

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE knowledge_base/<id>`
     */
    public function knowledgeDelete(Request $request, $id)
    {
        DB::table('knowledge_base')->where('id', (int) $id)->delete();
        DB::table('knowledge_base_links')->where('article_id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET knowledge_base/cats` / `POST knowledge_base/cats`
     */
    public function knowledgeCats(Request $request)
    {
        return $this->ok(DB::table('knowledge_base_cats')->orderBy('id')->get()->map(fn ($c) => (array) $c)->all());
    }

    public function knowledgeCatSave(Request $request)
    {
        $name = trim((string) $request->input('name', $request->input('title', '')));

        if ($name === '') {
            return $this->validationFail('分类名称不能为空');
        }

        $data = [
            'name' => $name,
            'description' => (string) $request->input('description', ''),
            'hidden' => (int) $request->input('hidden', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('knowledge_base_cats')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('knowledge_base_cats')->insertGetId($data)], '添加成功');
    }

    public function knowledgeCatDelete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('knowledge_base_links')->where('category_id', $id)->exists()) {
            return $this->fail('该分类下还有文章，不能删除');
        }

        DB::table('knowledge_base_cats')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 文件下载
    // -----------------------------------------------------------------

    /**
     * `GET product_downloadcates` — categories plus the file list, used by the
     * 文件下载 tab of the product editor and the 站务 download page.
     */
    public function downloadCates(Request $request)
    {
        $cats = DB::table('downloadcats')->orderBy('sort')->get()->map(function ($cat) {
            $row = (array) $cat;
            $row['count'] = DB::table('downloads')->where('category', $cat->id)->count();

            return $row;
        })->all();

        return $this->ok([
            'cat' => $cats,
            'cats' => $cats,
            'file' => DB::table('downloads')->orderByDesc('id')->get()->map(fn ($f) => (array) $f)->all(),
        ]);
    }

    /**
     * `GET downloads/cat`
     */
    public function downloadCatList(Request $request)
    {
        return $this->ok(DB::table('downloadcats')->orderBy('sort')->get()->map(fn ($c) => (array) $c)->all());
    }

    /**
     * `POST downloads/cat`
     */
    public function downloadCatSave(Request $request)
    {
        $name = trim((string) $request->input('name', $request->input('title', '')));

        if ($name === '') {
            return $this->validationFail('分类名称不能为空');
        }

        $data = [
            'name' => $name,
            'description' => (string) $request->input('description', ''),
            'parentid' => (int) $request->input('parentid', 0),
            'hidden' => (int) $request->input('hidden', 0),
            'sort' => (int) $request->input('sort', 0),
            'update_time' => time(),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('downloadcats')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();

        return $this->ok(['id' => (int) DB::table('downloadcats')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE downloads/cat/<id>`
     */
    public function downloadCatDelete(Request $request, $id)
    {
        DB::table('downloadcats')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET downloads/file`
     */
    public function downloadFileList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('downloads');

        if ($category = $request->input('category', $request->input('cat_id'))) {
            $query->where('category', (int) $category);
        }

        if ($title = trim((string) $request->input('title', $request->input('keywords', '')))) {
            $query->where('title', 'like', "%{$title}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated($rows->map(fn ($r) => (array) $r)->all(), $total, $page, $limit, [
            'cats' => DB::table('downloadcats')->orderBy('sort')->get()->toArray(),
        ]);
    }

    /**
     * `POST downloads/file`
     */
    public function downloadFileSave(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return $this->validationFail('文件名称不能为空');
        }

        $data = [
            'category' => (int) $request->input('category', $request->input('cat_id', 0)),
            'title' => $title,
            'description' => (string) $request->input('description', ''),
            'location' => (string) $request->input('location', ''),
            'locationname' => (string) $request->input('locationname', ''),
            'filetype' => (string) $request->input('filetype', ''),
            'url' => (string) $request->input('url', ''),
            'type' => (string) $request->input('type', ''),
            'clientsonly' => (int) $request->input('clientsonly', 0),
            'hidden' => (int) $request->input('hidden', 0),
            'productdownload' => (int) $request->input('productdownload', 0),
            'update_time' => time(),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('downloads')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $data['downloads'] = 0;

        return $this->ok(['id' => (int) DB::table('downloads')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE downloads/file/<id>`
     */
    public function downloadFileDelete(Request $request, $id)
    {
        DB::table('downloads')->where('id', (int) $id)->delete();
        DB::table('product_downloads')->where('download_id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // 黑名单 / 系统信息
    // -----------------------------------------------------------------

    /**
     * `GET user/get_black_list`
     */
    public function blackList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('blacklist');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($r) => (array) $r)->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST user/black_list` — block an IP or username.
     */
    public function blackListSave(Request $request)
    {
        $username = trim((string) $request->input('username', ''));

        if ($username === '') {
            return $this->validationFail('请输入要屏蔽的IP或用户名');
        }

        $id = (int) DB::table('blacklist')->insertGetId([
            'ip' => 0,
            'username' => $username,
            'type' => (int) $request->input('type', 1),
            'create_time' => time(),
        ]);

        $this->log('添加黑名单：'.$username, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `DELETE user/black_list/<id>`
     */
    public function blackListDelete(Request $request, $id)
    {
        DB::table('blacklist')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET database/backup` — the 数据库 pages' table summary.
     */
    public function databaseBackup(Request $request)
    {
        $rows = DB::select('SHOW TABLE STATUS');

        $tables = array_map(fn ($row) => [
            'name' => $row->Name,
            'engine' => $row->Engine,
            'rows' => (int) $row->Rows,
            'size' => (int) $row->Data_length + (int) $row->Index_length,
            'comment' => $row->Comment,
        ], $rows);

        return $this->ok([
            'tables' => $tables,
            'count' => count($tables),
            'total_size' => array_sum(array_column($tables, 'size')),
        ]);
    }

    /**
     * `GET upgrade/version` — the platform version banner.
     */
    public function systemVersion(Request $request)
    {
        return $this->ok([
            'version' => app()->version(),
            'php_version' => PHP_VERSION,
            'name' => \App\Support\ApiResponse::siteName(),
            'edition' => 1,
            'license_type' => 1,
        ]);
    }

    /**
     * `GET upgrade/checkautoupdate` / `GET upgrade/sqlupdate` — the update
     * check. This port ships its own release process, so the check reports the
     * running version as current.
     */
    public function checkUpdate(Request $request)
    {
        return $this->ok([
            'version' => app()->version(),
            'update' => 0,
            'new_version' => app()->version(),
            'sql' => [],
            'msg' => '已是最新版本',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The link catalogue the menu builder offers.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function linkCatalogue(): array
    {
        return [
            ['name' => '首页', 'url' => '/'],
            ['name' => '产品中心', 'url' => '/cart'],
            ['name' => '客户中心', 'url' => '/clientarea'],
            ['name' => '账单管理', 'url' => '/billing'],
            ['name' => '工单中心', 'url' => '/ticket'],
            ['name' => '帮助中心', 'url' => '/help'],
            ['name' => '新闻中心', 'url' => '/news'],
            ['name' => '知识库', 'url' => '/knowledge'],
        ];
    }
}
