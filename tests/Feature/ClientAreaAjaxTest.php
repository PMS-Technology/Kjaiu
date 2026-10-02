<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\Support\InteractsWithClientArea;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductFirstGroup;
use App\Models\ProductGroup;
use App\Models\TicketDepartment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * The client-area AJAX surface.
 *
 * These endpoints are called directly by the client-area JavaScript, so their
 * request field names and `{status, msg, data}` envelope are part of the
 * contract with the original templates.
 */
class ClientAreaAjaxTest extends BaseTestCase
{
    use InteractsWithClientArea;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeClient();
    }

    protected function tearDown(): void
    {
        $this->removeClient();

        parent::tearDown();
    }

    /**
     * Assert the platform envelope and return the decoded payload.
     */
    protected function envelope(\Illuminate\Testing\TestResponse $response, int $status = 200): array
    {
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
            'Non-JSON response (HTTP ' . $response->getStatusCode() . '): '
                . mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $response->getContent())), 0, 300)
        );

        $json = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($json, 'Response body was not a JSON object');

        $this->assertSame($status, (int) $json['status'], 'msg=' . ($json['msg'] ?? ''));

        return $json;
    }

    // -----------------------------------------------------------------
    // Account
    // -----------------------------------------------------------------

    public function test_user_info_returns_account_payload(): void
    {
        $json = $this->envelope($this->post('/user_info'));

        $this->assertSame($this->client->id, (int) $json['data']['user']['id']);
        $this->assertArrayHasKey('credit', $json['data']['user']);
        $this->assertArrayHasKey('certifi', $json['data']['user']);
    }

    public function test_modify_password_requires_the_current_password(): void
    {
        $json = $this->envelope($this->post('/modify_password', [
            'flag' => 1,
            'old_password' => 'wrong-password',
            'password' => 'brandnew123',
            're_password' => 'brandnew123',
        ]), 400);

        $this->assertStringContainsString('原密码', $json['msg']);
    }

    public function test_api_key_can_be_read_and_reset(): void
    {
        $before = (string) $this->client->api_password;

        $json = $this->envelope($this->post('/zjmf_finance_api/reset'));
        $this->assertNotSame($before, $json['data']['api_password']);

        $read = $this->envelope($this->post('/get_api_pwd'));
        $this->assertSame($json['data']['api_password'], $read['data']['api']);
    }

    public function test_api_can_be_closed(): void
    {
        $json = $this->envelope($this->post('/zjmf_finance_api/open', ['api_open' => 0]));

        $this->assertSame(0, (int) $json['data']['api_open']);
    }

    public function test_message_read_and_delete_markers(): void
    {
        $id = DB::table('system_message')->insertGetId([
            'uid' => $this->client->id,
            'title' => '测试消息',
            'content' => '内容',
            'obj' => '',
            'attachment' => '',
            'type' => 3,
            'is_market' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'read_time' => 0,
        ]);

        $this->envelope($this->post('/read_messgage', ['ids' => [$id]]));
        $this->assertGreaterThan(0, (int) DB::table('system_message')->where('id', $id)->value('read_time'));

        $this->envelope($this->get('/delete_messgage?ids[]=' . $id));
        $this->assertGreaterThan(0, (int) DB::table('system_message')->where('id', $id)->value('delete_time'));

        DB::table('system_message')->where('id', $id)->delete();
    }

    // -----------------------------------------------------------------
    // Cart
    // -----------------------------------------------------------------

    public function test_cart_summary_and_clear(): void
    {
        $json = $this->envelope($this->get('/cart/summary'));

        $this->assertArrayHasKey('cart_products', $json['data']);
        $this->assertSame('0.00', $json['data']['total_price']);

        $this->envelope($this->post('/cart/clear'));
    }

    public function test_cart_rejects_an_unknown_promo_code(): void
    {
        $json = $this->envelope($this->post('/cart/add_promo', ['promo' => 'NOPE-NOT-REAL']), 400);

        $this->assertStringContainsString('优惠码', $json['msg']);
    }

    public function test_cart_credit_payload_exposes_payment_options(): void
    {
        $json = $this->envelope($this->get('/cart/credit'));

        $this->assertArrayHasKey('credit', $json['data']);
        $this->assertArrayHasKey('gateways', $json['data']);
        $this->assertSame('100.00', $json['data']['credit']);
    }

    // -----------------------------------------------------------------
    // Billing
    // -----------------------------------------------------------------

    public function test_invoice_list_and_detail(): void
    {
        $invoice = Invoice::create([
            'uid' => $this->client->id,
            'invoice_num' => 'SMOKE' . random_int(1000, 9999),
            'create_time' => time(),
            'update_time' => time(),
            'due_time' => time() + 86400,
            'paid_time' => 0,
            'subtotal' => 10,
            'credit' => 0,
            'tax' => 0,
            'tax2' => 0,
            'total' => 10,
            'taxrate' => 0,
            'taxrate2' => 0,
            'status' => 'Unpaid',
            'type' => 'product',
            'is_delete' => 0,
            'use_credit_limit' => 0,
        ]);

        $list = $this->envelope($this->get('/get_invoices'));
        $this->assertGreaterThanOrEqual(1, count($list['data']['list']));

        $detail = $this->envelope($this->get('/get_invoices_detail?id=' . $invoice->id));
        $this->assertSame('Unpaid', $detail['data']['invoice']['status']);
        $this->assertSame('未支付', $detail['data']['invoice']['status_zh']);

        $read = $this->envelope($this->get('/invoices/' . $invoice->id));
        $this->assertSame((int) $invoice->id, (int) $read['data']['id']);
    }

    public function test_balance_pays_an_invoice(): void
    {
        $invoice = Invoice::create([
            'uid' => $this->client->id,
            'invoice_num' => 'PAY' . random_int(1000, 9999),
            'create_time' => time(),
            'update_time' => time(),
            'due_time' => time() + 86400,
            'paid_time' => 0,
            'subtotal' => 25,
            'credit' => 0,
            'tax' => 0,
            'tax2' => 0,
            'total' => 25,
            'taxrate' => 0,
            'taxrate2' => 0,
            'status' => 'Unpaid',
            'type' => 'product',
            'is_delete' => 0,
            'use_credit_limit' => 0,
        ]);

        $json = $this->envelope($this->post('/pay?action=billing', [
            'invoiceid' => $invoice->id,
            'use_credit' => 1,
            'pay' => 1,
        ]));

        $this->assertSame(1000, (int) $json['data']['status']);

        $invoice->refresh();
        $this->assertSame('Paid', (string) $invoice->status);
        $this->assertSame(75.0, (float) $this->client->fresh()->credit);
    }

    public function test_check_order_reports_paid_invoices(): void
    {
        $invoice = Invoice::create([
            'uid' => $this->client->id,
            'invoice_num' => 'CHK' . random_int(1000, 9999),
            'create_time' => time(),
            'update_time' => time(),
            'due_time' => time() + 86400,
            'paid_time' => time(),
            'subtotal' => 5,
            'credit' => 0,
            'tax' => 0,
            'tax2' => 0,
            'total' => 5,
            'taxrate' => 0,
            'taxrate2' => 0,
            'status' => 'Paid',
            'type' => 'product',
            'is_delete' => 0,
            'use_credit_limit' => 0,
        ]);

        $json = $this->envelope($this->post('/check_order', ['id' => $invoice->id]));

        $this->assertSame(1000, (int) $json['data']['status']);
    }

    // -----------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------

    public function test_ticket_lifecycle(): void
    {
        $department = TicketDepartment::query()->orderBy('id')->first();
        $this->assertNotNull($department, 'no ticket department seeded');

        $created = $this->envelope($this->post('/ticket/create', [
            'dptid' => $department->id,
            'title' => '测试工单标题',
            'content' => '这是一条测试工单内容',
            'priority' => 'medium',
        ]));

        $tid = $created['data']['tid'];
        $this->assertNotSame('', $tid);

        $row = DB::table('ticket')->where('uid', $this->client->id)->where('tid', $tid)->first();
        $this->assertNotNull($row);
        $this->assertSame('测试工单标题', $row->title);

        // Reply, then close.
        $this->envelope($this->post('/ticket/reply', ['tid' => $tid, 'content' => '追加说明']));
        $this->assertSame(1, DB::table('ticket_reply')->where('tid', $row->id)->count());

        $closed = $this->envelope($this->post('/ticket/close', ['tid' => $tid]));
        $this->assertSame('/supporttickets', $closed['data']['url']);
    }

    public function test_ticket_create_validates_required_fields(): void
    {
        $json = $this->envelope($this->post('/ticket/create', ['dptid' => 0]), 406);

        $this->assertNotSame('', $json['msg']);
    }

    // -----------------------------------------------------------------
    // Provisioning
    // -----------------------------------------------------------------

    public function test_provision_default_rejects_unknown_hosts(): void
    {
        $json = $this->envelope($this->post('/provision/default', ['id' => [99999999], 'func' => 'on']), 400);

        $this->assertStringContainsString('产品不存在', $json['msg']);
    }

    public function test_provision_default_requires_a_function(): void
    {
        $json = $this->envelope($this->post('/provision/default', ['id' => [1]]), 400);

        $this->assertStringContainsString('操作类型', $json['msg']);
    }

    // -----------------------------------------------------------------
    // Storefront
    // -----------------------------------------------------------------

    public function test_product_config_payload_shape(): void
    {
        $group = ProductFirstGroup::query()->create([
            'name' => 'Smoke Category',
            'hidden' => 0,
            'order' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $productGroup = ProductGroup::query()->create([
            'name' => 'Smoke Group',
            'gid' => $group->id,
            'hidden' => 0,
            'order' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $product = Product::query()->create([
            'name' => 'Smoke Product',
            'type' => 'cloud',
            'gid' => $productGroup->id,
            'hidden' => 0,
            'retired' => 0,
            'stock_control' => 0,
            'qty' => 0,
            'allow_qty' => 0,
            'pay_type' => json_encode(['monthly', 'annually']),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $json = $this->envelope($this->get('/cart/get_product_config?pid=' . $product->id));

        $this->assertArrayHasKey('option', $json['data']);
        $this->assertArrayHasKey('links', $json['data']);
        $this->assertArrayHasKey('cycle', $json['data']);

        // Clean up the catalogue fixtures.
        DB::table('products')->where('id', $product->id)->delete();
        DB::table('product_groups')->where('id', $productGroup->id)->delete();
        DB::table('product_first_groups')->where('id', $group->id)->delete();
    }

    public function test_graphic_captcha_is_served_as_an_image(): void
    {
        $response = $this->get('/verify?name=allow_login_email_captcha');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
        $this->assertNotSame('', (string) $response->getContent());
    }
}
