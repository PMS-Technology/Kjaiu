<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the administrator SPA shell.
 *
 * The administrator panel is a Vue application using hash routing, so only the
 * panel root needs to return HTML; every in-app route lives after the `#`. The
 * JSON API shares the same URL prefix and is matched by routes/admin.php, which
 * is registered ahead of this catch-all.
 */
class AdminSpaController extends Controller
{
    public function __invoke(): Response
    {
        $entry = public_path('admin-assets/index.html');

        if (! is_file($entry)) {
            return response(
                '<!doctype html><meta charset="utf-8"><title>Kjaiu 管理后台</title>'
                . '<p style="font:14px/1.6 system-ui;padding:40px">管理后台前端资源尚未构建。'
                . '请在 <code>admin-spa/</code> 目录执行 <code>npm install &amp;&amp; npm run build</code>。</p>',
                503,
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        // The shell is static; it is revalidated so a rebuilt bundle is picked
        // up without depending on cache expiry.
        return response(file_get_contents($entry), 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
