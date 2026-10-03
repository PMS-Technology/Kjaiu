
## Affiliate.affiliate  获取推介信息
URL: v1/affiliates
描述: 获取推介信息
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int |  | - | 推介计划ID,激活推介计划后才有此参数 | 1
  visitors | int |  | - | 访问数量,激活推介计划后才有此参数 | 1
  registcount | int |  | - | 注册数量 | 1
  payamount | int |  | - | 订购数量,激活推介计划后才有此参数 | 1
  audited_balance | float |  | - | 待确认佣金,激活推介计划后才有此参数 | 1.00
  balance | float |  | - | 可提现佣金,激活推介计划后才有此参数 | 1.00
  withdrawn | float |  | - | 已提现佣金,激活推介计划后才有此参数 | 1.00
  url_identy | string |  | - | 推介连接标识,激活推介计划后才有此参数 | NZINLELU
  sum | float |  | - | 总佣金,激活推介计划后才有此参数 | 1.00
  withdrawing | float |  | - | 提现中佣金,激活推介计划后才有此参数 | 1.00
  suffix | string |  | - | 货币标识后缀,激活推介计划后才有此参数 | HKD
  prefix | string |  | - | 货币标识前缀,激活推介计划后才有此参数 | $
  url | string |  | - | 推介链接,激活推介计划后才有此参数 | http://example.com/aff/DCALTMQE
  affiliate_is_renew | int |  | - | 是否开启续费:1是0否 | 1
  affiliate_is_reorder | int |  | - | 是否开启二次订单:1是0否 | 1
  affiliate_withdraw | float |  | - | 最低提现金额 | 1.00
  _commission | array[] |  | - | 收益方式 | 
  Lname | string |  | - | 名称 | 推介收益
  Ldescription | string |  | - | 描述 | 推介的用户注册购买产品后返佣金额
  Ltype | string |  | - | 类型 | 百分比
  Lcommission | string |  | - | 佣金 | 50%

## Affiliate.affiliateActive  激活推介计划
URL: v1/affiliates
描述: 激活推介计划
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  url | string |  | - | 推介链接 | http://example.com/aff/DCALTMQE

## Affiliate.affiliateRecord  推介记录
URL: v1/affiliates/record
描述: 推介记录
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 推介记录数量 | 1
  _record | array[] |  | - | 推介记录 | 
  Lid | int |  | - | 订单ID | 1
  Lstatus | int |  | - | 状态:Paid已支付Refunded已退款 | 1
  Lcreate_time | int |  | - | 账单创建时间 | 1648433070
  Ltype | string |  | - | 类型 | 续费
  Lpaid_time | int |  | - | 支付时间 | 1648433070
  Lusername | string |  | - | 客户名称 | example
  Luid | int |  | - | 客户ID | 1
  Lprefix | string |  | - | 货币标识前缀 | $
  Lsuffix | string |  | - | 货币标识后缀 | HKD
  Lcommmission | float |  | - | 佣金 | 1.00
  Lcommission_bates | float |  | - | 佣金比例,类型为金额时是固定金额,为比例时是百分比 | 50
  Lcommission_bates_type | int |  | - | 佣金类型:1金额2比例 | 1
  Linvoice_id | int |  | - | 账单ID | 1
  Lconfirm_status | int |  | - | 确认状态:0未确认1已确认 | 1
  Lamount | float |  | - | 金额 | 1.00
  Lconfirm_time | int |  | - | 确认时间 | 1648433070

## Affiliate.user  注册用户
URL: v1/affiliates/user
描述: 注册用户
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 注册用户数量 | 1
  _user | array[] |  | - | 注册用户 | 
  Lid | int |  | - | 注册用户ID | 1
  Lusername | string |  | - | 用户名 | example
  Lcompany_name | string |  | - | 公司名称 | example
  Lemail | string |  | - | 邮箱 | [email protected]
  Lphonenumber | string |  | - | 手机号 | 13312341234
  Lcreate_time | int |  | - | 创建时间 | 1648433070
  Llast_login_time | int |  | - | 最后登录时间 | 1648433070

## Affiliate.withdraw  推介计划申请提现
URL: v1/affiliates/withdraw
描述: 推介计划申请提现
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  num | float |  | - | 提现数量 | 1.00

## Affiliate.withdrawRecord  推介计划提现记录
URL: v1/affiliates/withdraw_record
描述: 推介计划提现记录
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 提现记录数量 | 1
  _record | array[] |  | - | 提现记录 | 
  Lid | int |  | - | 提现记录ID | 1
  Lnum | float |  | - | 提现数量 | 1.00
  Ltype | int |  | - | 提现方式:0提现中1余额2仅记录3流水支持 | 1
  Lcreate_time | int |  | - | 提现时间 | 1648433070
  Lstatus | int |  | - | 状态:1待审核2审核通过3拒绝 | 1
  Ladmin | string |  | - | 操作人 | example
  Lreason | string |  | - | 原因 | example
  Lsuffix | string |  | - | 货币标识后缀,激活推介计划后才有此参数 | HKD

## Basice.jwt  头部公共请求参数
描述: 登录后请求的接口需要在header中增加jwt参数，jwt有效时间2小时，过时需要重新登陆
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  authorization | string | 要验证登陆的接口必填该参数 | - | 在请求的头部传该参数，注意：JWT后面有个空格 | authorization:JWT eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyaW5mbyI6eyJpZCI6MSwidXNlcm5hbWUiOiJcdTcxOGFcdTcwNzVcdTUxNDMifSwiaXNzIjoid3d3LmlkY1NtYXJ0LmNvbSIsImF1ZCI6Ind3dy5pZGNTbWFydC5jb20iLCJpcCI6IjEyNy4wLjAuMSIsImlhdCI6MTY0MDg0NTA1NiwibmJmIjoxNjQwODQ1MDU2LCJleHAiOjE2NDA4NTIyNTZ9.sMFtkIhPOlTJkozw3b0_8zdj-AL6pf-vQ0SlNAHers0

## Basice.pages  分页和搜索参数
描述: 分页和搜索参数
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  page | string | 必填 | - | 页数 | 1
  limit | string | 必填 | - | 分页条数 | 20
  orderby | string |  | - | 排序字段 | 
  sort | string |  | - | DESC降序，ASC升序，只有这有这两个值 | DESC
  keywords | string |  | - | 搜索 | 

## Basice.result  接口通用返回说明
描述: 接口通用返回信息，状态码以及返回信息说明
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  msg | string |  | - | 成功或失败信息 | 
  状态码 | 描述
  200 | 成功
  400 | 失败

## Cart.addProducts  添加商品至购物车
URL: v1/cart/products
描述: 添加商品至购物车
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  product_id | int | require | 11 | 商品ID | 1
  billingcycle | string | require |  | 购买周期 | monthly
  qty | int | require |  | 购买数量 | 1
  host | string | require |  | 主机名 | ser422497445528
  password | string | require |  | 密码 | daLSEOK4wUIR
  configoption | json | require |  | 商品配置项数组:键为配置项ID,值为配置子项ID(注意:当配置项类型为数量时,传数量;当配置项类型为单选框,且不勾选时,不传此configoption值) | {"15297":85257,"15303":1}
  customfield | json |  |  | 有自定义字段时,传此值;键为自定义字段ID,值为客户填写 | {"89":无,"90":"test"}

## Cart.cartAddPromo  应用优惠码至购物车
URL: v1/cart/promo
描述: 应用优惠码至购物车
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  promo | string |  |  | 优惠码 | 1YMLbk64

## Cart.cartCheckout  购物车结算
URL: v1/cart/checkout
描述: 购物车结算
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  payment | string | require |  | 支付方式 | 1
  position | array[] | require |  | 购物车商品位置信息:0开始的自然数,如0,1,2 | pos[]:0 pos[]:1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int |  | 11 | 账单ID:状态码为200的时候返回;状态码为1001时,表示购买成功,并且不需要支付 | 1

## Cart.cartClear  清空购物车
URL: v1/cart/clear
描述: 清空购物车

## Cart.cartPage  获取购物车信息
URL: v1/cart
描述: 获取购物车信息
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  position | array[] |  |  | 购物车商品位置信息:0开始的自然数,如0,1,2 | position[]:0 position[]:1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  total_price | price |  | - | 总价 | 1002.00
  saleproducts | price |  | - | 折扣后价格(仅拥有折扣返回) | 1002.00
  default_gateway | string |  | - | 默认支付方式 | WxPay
  _gateway_list | array[] |  | - | 支持的支付方式 | { "name": "WxPay", "title": "微信支付", "url": "upload/pay/WxPay.png", "author_url": "data:image/png;base64,iVBORw0KGgoA……" }
  Lid | int |  | - | 支付方式ID | 1
  Lname | string |  | - | 支付方式标识 | WxPay
  Ltitle | string |  | - | 支付方式名称 | 微信支付
  Lurl | string |  | - | 支付方式图标:资源地址(已舍弃) | upload/pay/WxPay.png
  Lauthor_url | base64 |  | - | 支付方式图标:base64数据 | data:image/png;base64,iVBORw0KGgoA……
  _cart_products | array[] |  | - | 购物车商品数据 | { "productid": "379", "productsname": "升降级8001", "api_type": "normal", "groupn": { "id": 1, "groupname": "云服务器", "fa_icon": "el-icon-menu", "order": 0, "hostid": 0 }, "pay_ontrial_cycle": "10", "pay_ontrial_cycle_type": "day", "product_type": "dcimcloud", "host_show": "0", "password_show": "0", "is_truename": 0, "host": "ser422497445528", "password": "daLSEOK4wUIR", "allow_qty": 0, "os_name": "", "billingcycle": "monthly", "qty": 1, "billingcycle_desc": "月", "product_base_sale": "1000.00", "product_base_sale_setupfee": 0, "product_pricing": "1002.00", "product_sale": "600.000", "pricing": "601.00", "setup_pricing": "0.00", "product_sale_setupfee": 0, "type": { "type": 1, "bates": 6 }, "_sale_price": "601.20", "saleproducts": "400.80" }
  Lproductid | int |  | - | 商品ID | 379
  Lproductsname | string |  | - | 商品名称 | 升降级8001
  Lpay_ontrial_cycle | int |  | - | 商品试用周期 | 10
  Lpay_ontrial_cycle_type | string |  | - | 商品试用类型:day天,hour小时 | 10
  Lhost | string |  | - | 主机 | ser422497445528
  Lpassword | string |  | - | 密码 | daLSEOK4wUIR
  Lallow_qty | int |  | - | 是否允许购买多个 | 1
  Lbillingcycle | string |  | - | 周期 | monthly
  Lqty | int |  | - | 购买数量 | 1
  Lproduct_pricing | price |  | - | 单个商品价格 | 1002.00
  Lsetup_pricing | price |  | - | 初装费 | 1002.00
  Lsaleproducts | price |  | - | 折扣 | 400.80
  L_type | array[] |  | - | 折扣类型:1百分比,2固定金额(当有折扣才返回) | { "type": 1, "bates": 6 }
  Ltype | int |  | - | 折扣类型:1百分比,2固定金额 | 1
  Lbates | float |  | - | 折扣 | 6
  _promo | array[] |  | - | 优惠码,应用优惠码成功后有此字段 | { "promo": "1YMLbk64", "promo_desc": "百分比 6折终身", "promo_desc_str": "1YMLbk64 折扣: 6折 终身", "promo_price": "652.48", "promo_price_str": "-￥652.48元" }
  Lpromo | string |  | - | 优惠码 | 1YMLbk64
  Lpromo_desc | string |  | - | 优惠码描述 | 百分比 6折终身
  Lpromo_price | string |  | - | 优惠码优惠价格 | 652.48
  _client | array[] |  | - | 客户信息(登录后返回此字段) | { "credit": "6001.10", "credit_limit": "2000.00", "is_open_credit_limit": 1, }
  Lusername | string |  | - | 姓名 | test
  Lemail | string |  | - | 邮箱 | [email protected]
  Laddress1 | string |  | - | 地址 | 重庆市
  Lphonenumber | string |  | - | 手机号码 | 12345678910
  Lcredit | price |  | - | 余额 | 1000.00
  Lcredit_limit_balance | price |  | - | 信用额(客户剩余信用额) | 2000.00
  Lis_open_credit_limit | int |  | - | 是否开启信用额:1是,0否 | 1

## Cart.cartRemove  从购物车删除商品
URL: v1/cart/products/:position
描述: 从购物车删除商品
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  position | int |  | 11 | 购物车商品位置信息,0开始的自然数 | 1

## Cart.cartRemovePromo  购物车移除优惠码
URL: v1/cart/promo
描述: 购物车移除优惠码

## Cart.products  商品概要
URL: v1/products
描述: 商品概要
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  first_group_id | int | first_group_id与group_id、product_id三选一或者不传 | 11 | 一级分类ID | 1
  group_id | int | first_group_id与group_id、product_id三选一或者不传 | 11 | 商品分组ID | 1
  product_id | int | first_group_id与group_id、product_id三选一或者不传 | 11 | 商品ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  _first_group | array[] |  | - | 一级分类信息 | 
  Lid | int |  | - | 一级分类ID | 1
  Lname | int |  | - | 一级分类名称 | 分类1
  L_custom_fields | array[] |  | - | 一级分类自定义字段 | 
  Lname | string |  | - | 名称 | test
  Lvalue | string |  | - | 值 | 11
  L_group | array[] |  | - | 商品分组信息 | 1
  Lid | int |  | - | 商品分组ID | 1
  Lname | int |  | - | 商品分组名称 | 商品分组1
  Lheadline | string |  | - | 商品分组标题 | 
  Ltagline | string |  | - | 商品分组标语 | 
  L_custom_fields | array[] |  | - | 商品分组自定义字段 | 
  Lname | string |  | - | 名称 | test
  Lvalue | string |  | - | 值 | 11
  L_product | array[] |  | - | 商品列表信息 | 
  Lid | int |  | - | 商品ID | 1
  Ltype | string |  | - | 商品类型 | dcimcloud
  Lname | string |  | - | 商品名称 | 魔方云
  Ldescription | string |  | - | 商品描述 | 这是一个商品描述
  Lbillingcycle | string |  | - | 商品周期 | onetime
  Lproduct_price | price |  | - | 商品价格 | 100.00
  Lsetup_fee | price |  | - | 初装费 | 10.00
  Lstock_control | int |  | - | 库存控制:0未开启,1已开启 | 1
  Lqty | int |  | - | 库存 | 10
  L_ontrial | array[] |  | - | 试用信息 | 
  Lontrial | int |  | - | 是否启用试用:1启用,0否 | 1
  Lontrial_cycle | int |  | - | 试用周期:当ontrial=1时,才会返回此字段 | 10
  Lontrial_cycle_type | int |  | - | 试用类型:天day,小时hour:当ontrial=1时,才会返回此字段 | day
  Lontrial_price | price |  | - | 试用价格:当ontrial=1时,才会返回此字段 | 10.00
  Lontrial_setup_fee | price |  | - | 试用初装费:当ontrial=1时,才会返回此字段 | 0.00
  Lsale_price | price | 否 | - | 商品折扣后价格(当客户拥有此商品的分组折扣时,才返回) | 80.00
  Lbates | price | 否 | - | 商品折扣金额(当客户拥有此商品的分组折扣时,才返回) | 20.00

## Cart.productsConfig  商品详情
URL: v1/productsconfig
描述: 商品详情
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  first_group_id | int | first_group_id与group_id、product_id三选一 | 11 | 一级分类ID | 1
  group_id | int | first_group_id与group_id、product_id三选一 | 11 | 商品分组ID | 1
  product_id | int | first_group_id与group_id、product_id三选一 | 11 | 商品ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  _first_group | array[] |  | - | 一级分类信息 | 
  Lid | int |  | - | 一级分类ID | 1
  Lname | int |  | - | 一级分类名称 | 分类1
  L_custom_fields | array[] |  | - | 一级分类自定义字段 | 
  Lname | string |  | - | 名称 | test
  Lvalue | string |  | - | 值 | 11
  L_group | array[] |  | - | 商品分组信息 | 1
  Lid | int |  | - | 商品分组ID | 1
  Lname | int |  | - | 商品分组名称 | 商品分组1
  Lheadline | string |  | - | 商品分组标题 | 
  Ltagline | string |  | - | 商品分组标语 | 
  L_custom_fields | array[] |  | - | 商品分组自定义字段 | 
  Lname | string |  | - | 名称 | test
  Lvalue | string |  | - | 值 | 11
  L_products | array[] |  | - | 商品信息 | 
  Lid | int |  | - | 商品id | 1
  Lname | string |  | - | 商品名称 | 测试商品
  Ldescription | string |  | - | 商品描述 | 这是一个描述
  L_host | string |  | - | 产品主机名,及生成规则信息 | ser100000000
  Lshow | int |  | - | 是否显示:1是,0否 | 1
  Lprefix | int |  | - | 主机前缀(show为0时,不返回此字段) | ser
  Lhost | string |  | - | 主机名(show为0时,不返回此字段) | ser735157216706
  L_rule | array[] |  | - | 主机名生成规则(show为0时,不返回此字段) | ser
  Lupper | int |  | - | 是否含大写:1是,0否 | 1
  Llower | int |  | - | 是否含小写:1是,0否 | 1
  Lnum | int |  | - | 是否含数字:1是,0否 | 1
  Llen_num | int |  | - | 长度 | 12
  L_password | string |  | - | 产品密码,及生成规则信息 | ser100000000
  Lshow | int |  | - | 是否显示:1是,0否 | 1
  Lpassword | string |  | - | 密码(show为0时,不返回此字段) | u3GPInowGapB
  L_rule | array[] |  | - | 密码生成规则(show为0时,不返回此字段) | ser
  Lupper | int |  | - | 是否含大写:1是,0否 | 1
  Llower | int |  | - | 是否含小写:1是,0否 | 1
  Lnum | int |  | - | 是否含数字:1是,0否 | 1
  Llen_num | int |  | - | 长度 | 12
  Lspecial | int |  | - | 是否含特殊字符 | 1
  Lallow_qty | int |  | - | 是否允许购买多个:1是,0否 | 1
  Lstock_control | int |  | - | 是否开启库存控制:1是,0否 | 1
  Lqty | int |  | - | 库存 | 1
  L_cycle | array[] |  | - | 商品允许的周期 | 
  Lproduct_price | price |  | - | 商品价格 | 100.00
  Lsetup_fee | price |  | - | 商品初装费 | 0.00
  Lbillingcycle | string |  | - | 周期 | ontrial试用,monthly月付
  Lpay_ontrial_cycle | int |  | - | 周期为试用时的试用周期(仅周期为试用时才返回) | 10
  Lsaleprice | price |  | - | 折扣价格 | 80.00
  L_configoptions | array[] |  | - | 配置项信息 | 
  Lid | int |  | - | 配置项ID | 1
  Lname | string |  | - | 配置项名称 | 区域
  Ltype | int |  | - | 配置项类型:1Dropdown(默认)下拉,2radio单选,3Yes/No是/否,4quantity数量(应用价格), 5OperationSystem操作系统,6CpuDropdowncpu核心单选,7CpuQuantitycpu核心范围(应用价格), 8MemDropdown内存单选,9MemQuantity内存范围(应用价格),10BwDropdown带宽单选,11BwQuantity带宽范围(应用价格), 12LocationDropdown数据中心,13SystemDiskSizeDropdown系统盘容量单选,14SystemDiskSizeQuantity系统盘容量范围(应用价格), 15QuantityStage数量(阶段计费),16CpuQuantityStagecpu核心范围(阶段计费),17MemQuantityStage内存范围(阶段计费), 18BwQuantityStage带宽范围(阶段计费),19SystemDiskSizeQuantityStage系统盘容量范围(阶段计费),20RadioLevelLinkAge单选框-层级联动(专业版可用) | 1
  Lnotes | string |  | - | 备注 | 这是一个备注
  Lqty_minimum | int |  | - | 最小值(type为数量类型的才返回) | 1
  Lqty_maximum | int |  | - | 最大值(type为数量类型的才返回) | 100
  Lis_discount | int |  | - | 是否支持折扣:1是,0否 | 1
  Lunit | string |  | - | 配置项自定义单位 | M,GB
  L_sub | array[] |  | - | 子项 | 正常数据{ "id": 85310, "config_id": 15299, "qty_minimum": 0, "qty_maximum": 0, "option_name": "1核", "option_name_first": "1", "pricing": "0.00", "qty_stage": 0, "show_pricing": "1核 ￥0.00元" };当配置项为12区域时数据结构为:{ "option_name": "默认", "option_name_first": "1", "country_code": "CN", "area": [ { "id": 85257, "pricing": "0.00", "area": "", "area_zh": "", "show_pricing": " ￥0.00元" } ], "qty_stage": 0 };当配置项为5操作系统时数据结构为:"CentOS": { "child": [ { "id": 85259, "version": "CentOS-7.6.1810-x64", "pricing": "0.00", "show_pricing": "CentOS-7.6.1810-x64 ￥0.00元" }, { "id": 85260, "version": "CentOS-8.1.1911-x64", "pricing": "0.00", "show_pricing": "CentOS-8.1.1911-x64 ￥0.00元" }, ], "ico_url": "/upload/common/system/2.svg", "qty_stage": 0 }
  Lid | int |  | - | 配置子项id | 1
  Lqty_minimum | int |  | - | 子项最小值(配置项类型为数量类型时,才返回此字段) | 1
  Lqty_maximum | int |  | - | 子项最大值(配置项类型为数量类型时,才返回此字段) | 1
  Lname | string |  | - | 子项名称:子项|后的名称 | 1核
  Lpricing | string |  | - | 子项价格 | 0.00
  Lcountry_code | string |  | - | 国家代码(仅配置项类型为12LocationDropdown数据中心时才有返回字段) | CN
  Los_type | string |  | - | 操作系统类型(仅配置项类型为5OperationSystem操作系统时才有返回字段) | CentOS
  Los_url | string |  | - | 操作系统图标地址(仅配置项类型为5OperationSystem操作系统时才有返回字段) | /upload/common/system/2.svg
  L_custom_fields | array[] | 是 | - | 商品自定义字段 | { "id": 86, "fieldname": "面板管理密码", "description": "1", "fieldtype": "password", "fieldoptions": "", "regexpr": "1", "required": 0 }
  Lid | int |  | - | 自定义字段ID | 86
  Lfieldname | string |  | - | 自定义字段名称 | 面板管理密码
  Ldescription | string |  | - | 自定义字段描述 | 这是测试描述
  Lfieldtype | string |  | - | 自定义字段类型:password密码框,text文本,link链接,textarea文本域,dropdown下拉,tickbox选择框 | password
  Lfieldoptions | string |  | - | 选项,用英文半角逗号分隔 | 1;2;3;4;5
  Lregexpr | string |  | - | 正则表达式验证字符串 | \d+
  Lrequired | int |  | - | 是否必填:1是,0否 | 1

## Cart.productsTotal  计算总价
URL: v1/products/total
描述: 计算总价
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  product_id | int | require | 11 | 商品ID | 1
  billingcycle | string | require |  | 购买周期 | monthly
  qty | int | require |  | 购买数量 | 1
  configoption | json | require |  | 商品配置项数组:键为配置项ID,值为配置子项ID(注意:当配置项类型为数量时,传数量;当配置项类型为单选框,且不勾选时,不传此configoption值) | {"15297":85257,"15303":1}
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | price |  |  | 总价 | 100.00
  sale_total | price |  |  | 折扣价 | 80.00

## Downloads.downloads  资源下载
URL: v1/downloads/:id
描述: 资源下载

## Downloads.getDownloads  获取资源下载列表
URL: v1/downloads
描述: 获取资源下载列表
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _cate | array[] |  | - | 资源分类 | 
  Lid | int |  | - | 资源分类ID | 1
  Lname | string |  | - | 资源分类名称 | example
  Ldescription | string |  | - | 描述 | example
  Lcount | int |  | - | 资源数量 | 1
  _downloads | array[] |  | - | 资源下载 | 
  Lid | int |  | - | 资源ID | 1
  Lcategory | int |  | - | 分类ID | 1
  Ltype | int |  | - | 类型 | 1
  Ltitle | string |  | - | 资源标题 | example
  Ldescription | string |  | - | 描述 | example
  Ldownloads | int |  | - | 资源下载次数 | 1
  Lupdate_time | int |  | - | 资源更新时间 | 1648433070
  Llink | string |  | - | 文件下载链接 | 1

## Host.bmc  重置bmc
URL: /v1/hosts/:id/module/bmc
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.charts  图表
URL: /v1/hosts/:id/module/charts
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string | 必填 | - | 主机支持的server模块中图表的选项对应 | 
  start | int |  | - | 图表的开始时间,精确到毫秒 | 1644401280000
  end | int |  | - | 图表的结束时间,精确到毫秒 | 1645092240000
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  unit | string |  | - | 单位 | 
  chart_type | string |  | - | 图表类型 | 
  label | string |  | - | 显示标签 | 
  _list | array |  | - | 数据 | 
  Ltime | string |  | - | 时间 | 
  Lvalue | string |  | - | 值 | 

## Host.custom  自定义函数
URL: /v1/hosts/:id/module/custom
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  key | string | 必填 | - | 在获取的模块接口中类型是custom的方法名称 | snapshot

## Host.deleteCancel  取消停用
URL: /v1/hosts/:id/cancel
描述: 取消停用

## Host.getCancelPage  获取停用信息
URL: /v1/hosts/:id/cancel
描述: 获取停用信息
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _cancel | array[] |  | - | 停用信息 | ["example"]
  Ltype | string |  | - | 停用类型:Immediate立即,Endofbilling等待账单周期结束 | Immediate
  Lreason | string |  | - | 停用原因 | example

## Host.getHostDetail  获取指定产品
URL: /v1/hosts/:id
描述: 获取指定产品
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _host | array[] |  |  | 产品 | 
  Lid | int |  |  | 产品ID | 1
  Ltype | string |  | - | 产品类型 | server
  Ldomain | string |  | - | 产品名称 | example
  Linitiative_renew | int |  | - | 是否自动续费:0否,1是 | 1
  Lproduct_id | int |  |  | 商品ID | 1
  Lproduct_name | string |  | - | 商品名称 | example
  Lregdate | int |  | - | 开通时间 | 1639978741
  Lpayment | string |  | - | 支付方式 | WxPay
  Lgroup_id | int |  | - | 商品分组ID | 1
  Lfirstpaymentamount | float |  | - | 首付金额 | 100.00
  Lamount | float |  | - | 续费金额 | 100.00
  Lbillingcycle | string |  | - | 付款周期 | monthly
  Lnextduedate | int |  | - | 到期时间 | 1642657162
  Ldedicatedip | string |  | - | 独立ip地址 | 192.168.1.1
  Lassignedips | array[] |  | - | 分配的IP地址 | ["192.168.1.1", "192.168.1.2"]
  Ldomainstatus | string |  | - | 主机状态 | Active
  Lusername | string |  | - | 用户名 | root
  Lpassword | string |  | - | 密码 | iCjpmVtvAy6H
  Lsuspend_type | string |  | - | 暂停类型 | type
  Lsuspend_reason | string |  | - | 暂停原因 | example
  Lproduct_id | int |  | - | 商品ID | 1
  Lbwusage | float |  | - | 当前使用流量 | 0
  Lbwlimit | int |  | - | 当前使用流量上限(0表示不限) | 0
  Los | string |  | - | 显示的操作系统 | CentOS-7.6.1810-x64
  Lremark | string |  | - | 备注 | 备注
  Lport | int |  | - | 端口 | 80
  Lconfig_options_upgrade | int |  | - | 是否支持升级可配置选项:0否1是 | 1
  Lgroup_name | string |  | - | 产品分组名称 | 产品分组名称
  Lip_num | int |  | - | IP数量 | 1
  L_config_option | array[] |  | - | 产品配置项 | 
  Lid | int |  | - | 配置项ID | 1
  Ltype | int |  | - | 类型 | 1
  Lname | string |  | - | 名称 | example
  Lkey | string |  | - | 键 | key
  Lvalue | string |  | - | 值 | 1
  L_custom_field | array[] |  | - | 自定义字段 | 
  Lid | int |  | - | 自定义字段ID | 1
  Lname | string |  | - | 名称 | example
  Lvalue | string |  | - | 值 | 1

## Host.getHostDownloads  文件下载列表
URL: /v1/hosts/:id/downloads
描述: 文件下载列表
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _download | array[] |  | - | 文件下载列表 | [{"id":1,"name":"example","amount":1,"create_time":1639979575}]
  Lid | int |  | - | 文件下载ID | 1
  Lname | string |  | - | 名称 | example
  Lamount | int |  | - | 下载次数 | 1
  Lcreate_time | int |  | - | 创建时间 | 1639979575

## Host.getHostLogs  产品日志
URL: /v1/hosts/[:id]/logs
描述: 产品日志
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  page | int | require | - | 页数 | 1
  limit | int | require | - | 分页条数 | 20
  orderby | string |  | - | 排序字段 | 
  sort | string |  | - | DESC降序，ASC升序，只有这有这两个值 | DESC
  keywords | string |  | - | 搜索 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 数据条数 | 1
  _list | array[] |  | - | 日志列表 | [{"id":1,"uid":1,"user":"example","description":"example","ipaddr":"192.168.1.1","port":443,"create_time":1639979575}]
  Lid | int |  | - | 日志ID | 1
  Luid | int |  | - | 用户ID | 1
  Luser | string |  | - | 用户名 | example
  Ldescription | string |  | - | 描述 | example
  Lipaddr | string |  | - | IP地址 | 192.168.1.1
  Lport | int |  | - | 端口号 | 443
  Lcreate_time | int |  | - | 创建时间 | 1639979575

## Host.getHosts  获取所有产品
URL: /v1/hosts
描述: 获取所有产品
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  cate_id | int |  | - | 产品分类ID | 1
  page | int | require | - | 页数 | 1
  limit | int | require | - | 分页条数 | 20
  orderby | string |  | - | 排序字段 | 
  sort | string |  | - | DESC降序，ASC升序，只有这有这两个值 | DESC
  keywords | string |  | - | 搜索 | 
  domainstatus | array[] |  | - | 产品状态 | ["Active"]
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 数据条数 | 1
  _host | array[] |  | - | 产品 | [ { "id": 1, "type": "server", "domain": "example", "domainstatus": "Active", "regdate": 1639978741, "nextduedate": 1642657162, "firstpaymentamount": "100.00", "amount": "100.00", "billingcycle": "monthly", "dedicatedip": "192.168.1.1", "assignedips": [ "192.168.1.1", "192.168.1.2" ], "initiative_renew": 1, "notes": "备注", "product_id": 1, "product_name": "example", "host_cancel": "{"type": "立即停用","reason": "立即停用"}", }]
  Lid | int |  | - | 产品ID | 1
  Ltype | string |  | - | 产品类型 | server
  Ldomain | string |  | - | 产品名称 | example
  Ldomainstatus | string |  | - | 产品状态 | Active
  Lregdate | int |  | - | 开通时间 | 1639978741
  Lnextduedate | int |  | - | 到期时间 | 1642657162
  Lfirstpaymentamount | float |  | - | 首付款金额 | 100.00
  Lamount | float |  | - | 续费金额 | 100.00
  Lbillingcycle | string |  | - | 付款周期 | monthly
  Ldedicatedip | string |  | - | IP地址 | 192.168.1.1
  Lassignedips | array[] |  | - | 附加IP地址 | ["192.168.1.1", "192.168.1.2"]
  Linitiative_renew | int |  | - | 自动续费状态:0否,1是 | 1
  Lremark | string |  | - | 备注 | 备注
  Lproduct_id | int |  | - | 商品ID | 1
  Lproduct_name | string |  | - | 商品名称 | example
  L_host_cancel | array[] |  | - | 取消请求数据 | {"type": "立即停用","reason": "立即停用"}
  Ltype | string |  | - | 类型 | 立即停用
  Lreason | string |  | - | 原因 | 立即停用
  domainstatus | array[] |  | - | 产品状态 | { "Pending": { "name": "待开通", "color": "#fca426" }, "Active": { "name": "已激活", "color": "#3fbf70" }, "Cancelled": { "name": "被取消", "color": "#959799" }, "Fraud": { "name": "有欺诈", "color": "#FF0000" }, "Deleted": { "name": "被删除", "color": "#2d2d2d" }, "Suspended": { "name": "已暂停", "color": "#e31519" } }

## Host.getHostsCates  获取产品分类
URL: /v1/hosts/cates
描述: 获取产品分类
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _cate | array[] |  | - | 产品分类 | [{"id":1,"name":"服务器"}]
  Lid | int |  | - | 产品分类ID | 1
  Lname | string |  | - | 产品分类名称 | 服务器

## Host.getReinstall  获取重装系统
URL: /v1/hosts/:id/module/reinstall
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _os | array |  | - | 操作系统 | 
  Los_id | int |  | - | 操作系统ID | 
  Lname | string |  | - | 操作系统名称 | 
  Lgroup_name | string |  | - | 操作系统组名称 | 
  _os_group | array |  | - | 操作系统组 | 
  Lgroup_name | string |  | - | 操作系统组名称 | 
  Limg | string |  | - | 操作系统组图片 | 

## Host.hard_off  硬关机
URL: /v1/hosts/:id/module/hard_off
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.hard_reboot  硬重启
URL: /v1/hosts/:id/module/hard_reboot
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.hostDownloadFile  下载文件
URL: /v1/hosts/:id/downloads/:id
描述: 下载文件

## Host.ikvm  ikvm
URL: /v1/hosts/:id/module/ikvm
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.kvm  kvm
URL: /v1/hosts/:id/module/kvm
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.module  获取主机支持的server模块接口
URL: /v1/hosts/:id/module
描述: 该主机支持的server模块接口
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string |  | - | default 默认 custom 自定义 | 
  function | string |  | - | 方法名称 | 
  name | string |  | - | 中文名 | 
  select | array |  | - | 选项 | 

## Host.off  关机
URL: /v1/hosts/:id/module/off
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.on  开机
URL: /v1/hosts/:id/module/on
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.postCancel  申请停用
URL: /v1/hosts/:id/cancel
描述: 申请停用
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string | 是 |  | 停用类型:Immediate立即,Endofbilling等待账单周期结束 | Immediate
  reason | string | 是 |  | 停用原因 | example

## Host.reboot  重启
URL: /v1/hosts/:id/module/reboot
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.reinstall  重装系统
URL: /v1/hosts/:id/module/reinstall
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  os_id | string | 必填 | - | 操作系统ID | 
  _dcim | array |  | - | 主机类型是dcim时可传参数 | 
  Lpassword | string | 必填 | - | 密码 | 
  Lport | int |  | - | 端口 | 
  Lpart_type | int |  | - | 分区类型，0全盘格式化，1第一分区格式化 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.reinstallBuy  购买重装次数
URL: /v1/hosts/:id/module/reinstall_buy
描述: 请求方式：POST
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int |  | - | 账单ID | 

## Host.renew  续费
URL: /v1/hosts/:id/renew
描述: 续费
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | require |  | 产品ID | 1
  billingcycle | string | require |  | 周期 | monthly
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int |  | - | 账单ID | 1
  payment | string |  | - | 支付方式 | WxPay

## Host.renewAuto  自动余额续费开关
URL: /v1/hosts/:id/renew
描述: 自动余额续费开关
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | require |  | 产品ID | 1
  initiative_renew | int | require |  | 是否自动余额续费:1是,0否 | 1

## Host.renewBatch  批量续费
URL: /v1/hosts/renew/batch
描述: 批量续费
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  ids | array[] | require |  | 产品ID数组 | [613,142]
  billingcycles | array[] |  |  | 产品对应周期 | {"613":"monthly","142":"monthly"}
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoice_id | int |  | - | 账单ID | 1
  payment | string |  | - | 支付方式 | WxPay

## Host.renewBatchPage  获取批量续费
URL: /v1/hosts/renew/batch
描述: 获取批量续费
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  ids | array[] | 是 |  | 产品ID数组 | [613,142]
  billingcycles | array[] | 否 |  | 产品对应周期 | {"613":"monthly","142":"monthly"}
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | price | 是 |  | 续费总价 | 1028.40
  totalsale | price | 是 |  | 折扣后续费总价 | 0.00
  _currency | array[] | 是 | - | 货币信息 | 
  Lid | int | 是 | - | 货币ID | 1
  Lcode | string | 是 | - | 货币代码 | CNY
  Lprefix | string | 是 | - | 货币前缀 | ￥
  Lsuffix | string | 是 | - | 货币后缀 | 元
  Ldefault | int | 是 | - | 是否默认货币:1是,0否 | 1
  _hosts | array[] | 是 |  | 产品信息 | { "productid": 1, "dedicatedip": "192.168.1.71", "uid": 7, "id": 142, "name": "魔方云主机", "nextduedate": 1638786314, "billingcycle": "monthly", "amount": "23.40", "flag": 1, "groupid": 1, "promoid": 0, "groupn": { "id": 1, "groupname": "云服务器", "fa_icon": "el-icon-menu", "order": 0 }, "saleproducts": 0, "nextduedate_renew": 1641464714, "allow_billingcycle": [ { "setup_fee": "1.00", "price": "60.00", "billingcycle": "hour", "billingcycle_zh": "小时", "amount": "54.00", "saleproducts": "36.00", "flags": 1 }, { "setup_fee": "10.00", "price": "20.00", "billingcycle": "day", "billingcycle_zh": "天", "amount": "12.00", "saleproducts": "8.00", "flags": 1 }, { "setup_fee": "10.00", "price": "20.00", "billingcycle": "monthly", "billingcycle_zh": "月付", "amount": "23.40", "saleproducts": 0 }, { "setup_fee": "20.00", "price": "40.00", "billingcycle": "quarterly", "billingcycle_zh": "季付", "amount": "36.00", "saleproducts": "24.00" }, { "setup_fee": "30.00", "price": "60.00", "billingcycle": "semiannually", "billingcycle_zh": "半年付", "amount": "36.00", "saleproducts": "24.00" } ], "flags": 1 }
  Lproductid | int | 是 |  | 商品ID | 1
  Ldedicatedip | string | 是 |  | 独立IP | 192.168.1.71
  Lname | string | 是 |  | 商品名称 | 魔方云主机
  Lnextduedate | int | 是 |  | 到期时间时间戳 | 1638786314
  Lbillingcycle | string | 是 |  | 周期 | monthly
  Lnextduedate | int | 是 |  | 到期时间时间戳 | 1638786314
  Lamount | price | 是 |  | 续费金额 | 23.40
  L_groupn | array[] | 是 |  | 产品菜单 | { "id": 1, "groupname": "云服务器", "fa_icon": "el-icon-menu", "order": 0 },
  Lid | int | 是 |  | 菜单ID | 1
  Lgroupname | string | 是 |  | 菜单名称 | 云服务器
  Lfa_icon | string | 是 |  | 图标 | 1el-icon-menu
  Lorder | int | 是 |  | 菜单排序 | 1
  Lpromoid | int | 是 |  | 优惠码ID | 1
  Lflags | int | 是 |  | 是否有折扣:1是,0否 | 1
  L_allow_billingcycle | array[] | 是 |  | 允许的周期 | { "setup_fee": "20.00", "price": "40.00", "billingcycle": "quarterly", "billingcycle_zh": "季付", "amount": "36.00", "saleproducts": "24.00" }
  Lbillingcycle | string | 是 |  | 周期 | quarterly
  Lbillingcycle_zh | string | 是 |  | 周期(中文) | 季付
  Lamount | price | 是 |  | 续费金额 | 36.00
  Lsetup_fee | price | 是 |  | 初装费 | 20.00
  Lprice | price | 是 |  | 商品价格 | 40.00
  Lsaleproducts | price | 是 |  | 折扣后价格 | 24.00

## Host.renewPage  获取续费
URL: /v1/hosts/:id/renew
描述: 获取续费
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | require |  | 产品ID | 1
  billingcycle | string |  | - | 周期 | monthly
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  _cycle | array[] |  | - | 商品可续费周期 | { "setup_fee": "0.00", "price": "1000.00", "billingcycle": "monthly", "billingcycle_zh": "月付", "amount": "1005.00", "saleproducts": "0.00" }
  Lbillingcycle | string |  | - | 周期 | monthly
  Lamount | price |  | - | 续费金额 | 1005.00
  Lsaleproducts | price |  | - | 折扣后续费金额 | 0.00
  Lprice | price |  | - | 商品价格 | 1000.00
  Lsetup_fee | price |  | - | 初装费价格 | 0.00
  _pay_type | array[] |  | - | 其它周期付款信息 | { "pay_type": "recurring", "pay_hour_cycle": "720", "pay_day_cycle": "30", "pay_ontrial_status": 1, "pay_ontrial_cycle": "10", "pay_ontrial_num": "1", "pay_ontrial_condition": [ "phone", "realname" ], "pay_ontrial_cycle_type": "day", "pay_ontrial_num_rule": "0", "clientscount_rule": "0" }
  Lpay_type | string |  | - | 付款周期 | recurring
  Lpay_hour_cycle | int |  | - | 小时付周期 | 720
  Lpay_day_cycle | int |  | - | 天付周期 | 30
  Lpay_ontrial_status | int |  | - | 是否支持试用:1是,0否 | 1
  Lpay_ontrial_cycle_type | string |  | - | 试用周期类型:day天,hour小时 | 1
  Lpay_ontrial_cycle | int |  | - | 试用周期周期 | 10
  Lpay_ontrial_condition | array[] |  | - | 试用条件:phone需要绑定手机号,realname需要实名认证 | [ "phone", "realname" ]
  Lpay_ontrial_num | int |  | - | 试用周期可购买数量:0表示无限制 | 10
  Lpay_ontrial_num_rule | int |  | - | 试用规则 | 1
  Lclientscount_rule | int |  | - | 试用数量计算规则:0任意状态产品,1激活状态产品 | 1

## Host.repassword  重置密码
URL: /v1/hosts/:id/module/repassword
描述: 重置密码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  password | string | 必填 | - | 主机密码 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.rescue  救援系统
URL: /v1/hosts/:id/module/rescue
描述: 请求方式：PUT
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  rescue_id | string | 必填 | - | 救援系统ID,这里固定只有linux和windows。linux传1，windows传2 | 2
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _second_verify | array |  | - | 需要二次验证，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | email
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Host.status  获取状态
URL: /v1/hosts/:id/module/status
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string | 必填 | - | host 服务器电源状态 reinstall 获取重装,救援系统,重置密码进度（dcim类型主机才有） | 

## Host.upgradeConfig1  升降级配置项
URL: /v1/hosts/:id/actions/upgradeconfig
描述: 升降级配置项
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  configoption | array[] | 是 |  | 选择的配置项、子项的数组(配置项为数量时,传数量;配置项为单选框时,不勾选不传) | {"1":"2","5":"6"}
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] | 是 | - | 货币信息 | 
  Lid | int | 是 | - | 货币ID | 1
  Lcode | string | 是 | - | 货币代码 | CNY
  Lprefix | string | 是 | - | 货币前缀 | ￥
  Lsuffix | string | 是 | - | 货币后缀 | 元
  name | string |  |  | 商品分组-商品名称(主机名) | 上下游同步问题分组1-产品升降级002(ser698889845905)
  payment | string |  |  | 默认支付方式 | WxPay
  payment | string |  |  | 默认支付方式 | WxPay
  saleproducts | price |  |  | 折扣后价格 | 80.00
  subtotal | price |  |  | 小计 | 100.00
  total | price |  |  | 总计 | 100.00
  promo_code | string |  |  | 优惠码 | 1YMLbk64
  billingcycle | string |  |  | 周期 | monthly
  configoptions | json |  |  | 选择的配置子项 | { "15383": "86039", "15384": "86041" }
  _alloption | array[] |  |  | 配置项信息 | 
  Loid | int |  |  | 配置项ID | 1620
  Loption_name | string |  |  | 配置项名称 | CPU
  Loption_type | int |  |  | 配置项类型 | 2
  Lsuboption_name | string |  |  | 新配置子项名称 | 4核
  Lold_suboption_name | string |  |  | 旧配置子项名称 | 2核
  Lold_qty | int |  |  | 配置项类型为数量时,旧配置项的数量(仅option_type=4/7/9/11/14/15/16/17/18/19时,返回此字段);附：配置项类型:1Dropdown(默认)下拉,2radio单选,3Yes/No是/否,4quantity数量(应用价格), 5OperationSystem操作系统,6CpuDropdowncpu核心单选,7CpuQuantitycpu核心范围(应用价格), 8MemDropdown内存单选,9MemQuantity内存范围(应用价格),10BwDropdown带宽单选,11BwQuantity带宽范围(应用价格), 12LocationDropdown数据中心,13SystemDiskSizeDropdown系统盘容量单选,14SystemDiskSizeQuantity系统盘容量范围(应用价格), 15QuantityStage数量(阶段计费),16CpuQuantityStagecpu核心范围(阶段计费),17MemQuantityStage内存范围(阶段计费), 18BwQuantityStage带宽范围(阶段计费),19SystemDiskSizeQuantityStage系统盘容量范围(阶段计费),20RadioLevelLinkAge单选框-层级联动(专业版可用) | 1
  Lqty | int |  |  | 配置项类型为数量时,新配置项的数量(仅option_type=4/7/9/11/14/15/16/17/18/19时,返回此字段);附：配置项类型:1Dropdown(默认)下拉,2radio单选,3Yes/No是/否,4quantity数量(应用价格), 5OperationSystem操作系统,6CpuDropdowncpu核心单选,7CpuQuantitycpu核心范围(应用价格), 8MemDropdown内存单选,9MemQuantity内存范围(应用价格),10BwDropdown带宽单选,11BwQuantity带宽范围(应用价格), 12LocationDropdown数据中心,13SystemDiskSizeDropdown系统盘容量单选,14SystemDiskSizeQuantity系统盘容量范围(应用价格), 15QuantityStage数量(阶段计费),16CpuQuantityStagecpu核心范围(阶段计费),17MemQuantityStage内存范围(阶段计费), 18BwQuantityStage带宽范围(阶段计费),19SystemDiskSizeQuantityStage系统盘容量范围(阶段计费),20RadioLevelLinkAge单选框-层级联动(专业版可用) | 5

## Host.upgradeConfigCheckout  配置项升降级结算
URL: /v1/hosts/:id/actions/upgradeconfig/checkout
描述: 配置项升降级结算
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1

## Host.upgradeConfigPage1  获取可升降级产品配置项
URL: /v1/hosts/:id/actions/upgradeconfig
描述: 获取可升降级产品配置项
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 613
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  pid | int | 是 |  | 商品ID | 1
  _currency | array[] | 是 | - | 货币信息 | 
  Lid | int | 是 | - | 货币ID | 1
  Lcode | string | 是 | - | 货币代码 | CNY
  Lprefix | string | 是 | - | 货币前缀 | ￥
  Lsuffix | string | 是 | - | 货币后缀 | 元
  _host | array[] | 是 |  | 配置项信息(可升降级的) | { "oid": 15305, "id": 15305, "flag": 1, "option_name": "IP数量", "option_type": 4, "qty": 2, "suboption_name": "IP数量", "suboption_name_first": "IP数量", "subid": 85333, "fee": "0.00", "setupfee": "0.00", "qty_minimum": 1, "qty_maximum": 20, "qty_stage": 0, "unit": "", "linkage_pid": 0, "linkage_top_pid": 0, "sub": [ { "id": 85333, "config_id": 15305, "qty_minimum": 1, "qty_maximum": 20, "option_name": "IP数量", "option_name_first": "IP数量", "pricing": "0.00", "qty_stage": 0, "show_pricing": "IP数量 ￥0.00元" } ] }
  Lid | int | 是 |  | 配置项ID | 15305
  Loption_name | string | 是 |  | 配置项名称 | CPU
  Loption_type | int | 是 |  | 配置项类型 | 6
  Lsuboption_name | string | 是 |  | 子项名称,|分隔符之后 | 1核
  Lsuboption_name_first | string | 是 |  | 子项名称,|分隔符之前 | 1
  Lsubid | int | 是 |  | 子项ID | 85310
  Lqty_stage | int | 是 |  | 是否开启数量阶梯:1是,0否 | 85310
  Lunit | string | 是 |  | 单位 | GB
  L_sub | array[] | 是 |  | 配置项 | { "id": 85310, "config_id": 15299, "qty_minimum": 0, "qty_maximum": 0, "option_name": "1核", "option_name_first": "1", "pricing": "0.00", "qty_stage": 0, "show_pricing": "1核 ￥0.00元" }
  Lid | int | 是 |  | 子项ID | 85310
  Lconfig_id | int | 是 |  | 配置项ID | 15299
  Loption_name | string | 是 |  | 配置项名称,|分隔符之后 | 1核
  Loption_name_first | string | 是 |  | 配置项名称,,|分隔符之前 | 1核
  Lqty_minimum | string | 是 |  | 子项最小值(配置项为数量类型时) | 1
  Lqty_maximum | string | 是 |  | 子项最大值(配置项为数量类型时) | 100
  Lpricing | price | 是 |  | 价格 | 100.00
  Lqty_stage | string | 是 |  | 数量阶梯:1开启,0否 | 1
  Lshow_pricing | string | 是 |  | 价格显示 | 1核 ￥0.00元

## Host.upgradeConfigPromo  配置项升降级应用优惠码
URL: /v1/hosts/:id/actions/upgradeconfig/promo
描述: 配置项升降级应用优惠码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  pormo_code | string | 是 |  | 优惠码 | rlA6e5F0

## Host.upgradeConfigPromoRemove  配置项升降级移除优惠码
URL: /v1/hosts/:id/actions/upgradeconfig/promo
描述: 配置项升降级移除优惠码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1

## Host.upgradeHost  产品升降级
URL: /v1/hosts/:id/actions/upgrade
描述: 产品升降级
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  product_id | int | 是 |  | 新商品ID | 1
  billingcycle | string | 是 |  | 周期 | monthly
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  name | string |  |  | 新商品名称 | 魔方云主机
  saleproducts | price |  |  | 折扣金额 | 14.40
  amount_total | price |  |  | 总价 | -700.79
  promo_code | string |  |  | 优惠码 | rlA6e5F0
  billingcycle | string |  |  | 周期 | monthly
  _old_host | array[] |  |  | 旧产品信息 | { "id": 647, "host": "上下游同步问题分组1-产品升降级001", "domain": "ser280455935849", "flag": 0 }
  Lid | int |  |  | 产品ID | 657
  Lhost | string |  |  | 旧产品信息 | 上下游同步问题分组1-产品升降级001
  Ldomain | string |  |  | 旧产品主机名 | ser280455935849

## Host.upgradeHostPage  获取产品升降级
URL: /v1/hosts/:id/actions/upgrade
描述: 获取产品升降级
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] | 是 | - | 货币信息 | 
  Lid | int | 是 | - | 货币ID | 1
  Lcode | string | 是 | - | 货币代码 | CNY
  Lprefix | string | 是 | - | 货币前缀 | ￥
  Lsuffix | string | 是 | - | 货币后缀 | 元
  _old_host | array[] | 是 |  | 原产品信息 | { "host": "升降级8001", "domain": "ser557409352685", "description": "CPU:2*e5-2450L\n内存:16G\n硬盘:250G SSD\nIP数量:3\n1:1", "pid": 379, "uid": 7, "flag": 1 }
  Lhost | string | 是 |  | 产品名称 | 魔方云
  Ldomain | string | 是 |  | 主机名 | ser557409352685
  Ldescription | string | 是 |  | 描述 | CPU:2*e5-2450L\n内存:16G\n硬盘:250G SSD\nIP数量:3\n1:1
  Lpid | int | 是 |  | 商品ID | 379
  _host | array[] | 是 |  | 新产品信息 | { "pid": 1, "host": "魔方云主机", "description": "a撒旦法撒旦法哈开始大复活卡a收待发送联动jafdasdf", "cycle": [ { "setup_fee": "1.00", "price": 42.6, "billingcycle": "hour", "billingcycle_zh": "小时", "amount": "0.00", "saleproducts": 0 }, { "setup_fee": "10.00", "price": 18, "billingcycle": "day", "billingcycle_zh": "天", "amount": "0.00", "saleproducts": 0 }, { "setup_fee": "20.00", "price": 33.6, "billingcycle": "monthly", "billingcycle_zh": "月付", "amount": "0.00", "saleproducts": 0 }, { "setup_fee": "40.00", "price": 60, "billingcycle": "quarterly", "billingcycle_zh": "季付", "amount": "0.00", "saleproducts": 0 }, { "setup_fee": "30.00", "price": 54, "billingcycle": "semiannually", "billingcycle_zh": "半年付", "amount": "0.00", "saleproducts": 0 } ] }
  Lpid | int | 是 |  | 商品ID | 1
  Lhost | string | 是 |  | 商品名称 | 魔方云主机
  Ldescription | string | 是 |  | 商品描述 | CPU:2*e5-2450L\n内存:16G\n硬盘:250G SSD\nIP数量:3\n1:1
  L_cycle | array[] | 是 |  | 商品可用周期 | { "setup_fee": "1.00", "price": 42.6, "billingcycle": "hour", "billingcycle_zh": "小时", "amount": "0.00", "saleproducts": 0 }
  Lsetup_fee | price | 是 |  | 商品当前周期初装费 | 20.00
  Lprice | price | 是 |  | 商品当前周期价格 | 33.60
  Lbillingcycle | string | 是 |  | 商品周期 | monthly
  Lsaleproducts | price | 是 |  | 折扣价格 | 0.00

## Host.upgradeProductAddPromo  产品升降级应用优惠码
URL: /v1/hosts/:id/actions/upgrade/promo
描述: 产品升降级应用优惠码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  promo_code | string | 是 |  | 优惠码 | 1YMLbk64

## Host.upgradeProductCheckout  产品升降级结算
URL: /v1/hosts/:id/actions/upgrade/checkout
描述: 产品升降级结算
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int |  |  | 账单ID | 551332
  orderid | int |  |  | 订单ID | 123

## Host.upgradeProductRemovePromo  产品升降级删除优惠码
URL: /v1/hosts/:id/actions/upgrade/promo
描述: 产品升降级删除优惠码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 产品ID | 1

## Host.vnc  VNC
URL: /v1/hosts/:id/module/vnc
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  url | string |  | - | 访问vnc的url地址 | 

## Invoices.accountsRecord  交易记录
URL: v1/transactions/funds
描述: 交易记录
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  total | int |  | - | 总数 | 元
  _accounts | int |  | - | 总数 | 元
  Lid | int |  | - | 交易流水id | 1
  Linvoice_id | int |  | - | 交易流水id | 1
  Lpay_time | int |  | - | 支付时间,时间戳 | 1
  Lpayment_zh | string |  | - | 支付方式 | 微信支付
  Ldescription | string |  | - | 描述 | ceshi
  Ltype | string |  | - | 账单类型 | recharge
  Ltrans_id | int |  | - | 流水id | 12341234
  Lamount_in | price |  | - | 金额 | 1
  L_refund | array[] |  | - | 退款 | 
  Lid | int |  | - | 退款流水id | 1
  Lamount_out | price |  | - | 退款金额 | 1.00

## Invoices.combineInvoices  合并账单
URL: v1/invoices/combines
描述: 合并账单
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  ids | array[] |  |  | 账单ID数组 | [1,2,3]
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int |  |  | 账单ID | 1

## Invoices.funds  账户充值
URL: v1/funds
描述: 账户充值
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  amount | price |  |  | 充值金额 | 0.01
  payment | string |  |  | 支付方式 | WxPay
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoice_id | int |  |  | 账单ID | 1

## Invoices.fundsInfo  账户充值信息
URL: v1/funds
描述: 账户充值信息
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _currency | array[] |  | - | 货币信息 | 
  Lid | int |  | - | 货币ID | 1
  Lcode | string |  | - | 货币代码 | CNY
  Lprefix | string |  | - | 货币前缀 | ￥
  Lsuffix | string |  | - | 货币后缀 | 元
  allow_recharge | int |  | - | 是否允许充值,1是，0否 | 1
  credit | price |  | - | 余额 | 100.00
  _gateways | array[] | 是 | - | 支持的支付方式 | { "id": 2, "name": "WxPay", "title": "微信支付", "status": 1, "module": "gateways", "url": "upload/pay/WxPay.png", "author_url": "data:image/png;base64,iVBORw0KGgoA……" }
  Lid | int | 是 | - | 支付方式ID | 1
  Lname | string | 是 | - | 支付方式标识 | WxPay
  Ltitle | string | 是 | - | 支付方式名称 | 微信支付
  Lurl | string | 是 | - | 支付方式图标:资源地址(已舍弃) | upload/pay/WxPay.png
  Lauthor_url | base64 | 是 | - | 支付方式图标:base64数据 | data:image/png;base64,iVBORw0KGgoA……
  addfunds_minimum | price |  | - | 充值最小值 | 1
  addfunds_maximum | price |  | - | 充值最大值 | 1
  addfunds_maximum_balance | price |  | - | 充值最大金额 | 1
  count | int |  | - | 账单数量 | 1
  _invoices | array[] |  | - | 交易流水 | { "trans_id": "2022022822001416851402771569", "amount_in": "0.01元", "pay_time": 1646027663, "gateway": "支付宝当面付", "amount_out": "0.00", "invoice_id": 551408, "description": "用户充值", "type": "充值" }
  Ltrans_id | int |  | - | 交易流水id | 1
  Lamount_in | price |  | - | 金额 | 1
  Lpay_time | int |  | - | 支付时间,时间戳 | 1
  Lgateway | string |  | - | 支付方式 | 1
  Linvoice_id | int |  | - | 账单id | 1
  Ldescription | int |  | - | 描述 | 1
  Ltype | string |  | - | 类型 | 1

## Invoices.invoices  获取账单详情
URL: v1/invoices/:id
描述: 获取账单详情
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | require | 11 | 账单ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _invoices | array[] |  | - | 账单信息 | 
  Llogo | string |  | - | 账单logo地址 | 
  Lusername | string |  | - | 支付方信息 | 
  Lcompanyname | string |  | - | 收款方信息 | 
  Lcreate_time | int |  | - | 创建时间 | 
  Lstatus | string |  | - | 支付状态:Paid已支付,Unpaid未支付,Refunded已退款,Cancelled被取消, Draft已草稿,Overdue已逾期,Collections已收藏 | Paid
  Ltotal | price |  | - | 账单总价 | 100.00
  L_invoice_items | array[] |  | - | 账单子项 | { "type": "host", "description": "产品升降级001 -ser571869537400(2022-01-06 16 - 2022-02-06 16) \n IP数量: 1\n CPU: 1核\n", "amount": "300.00", "rel_id": 625, }
  Ltype | string |  | - | 账单子项类型:host产品,renew续费,recharge充值,setup初装费,promo优惠码,upgrade升降级,客户折扣等 | host
  Ldescription | string |  | - | 描述 | 产品升降级001 -ser571869537400(2022-01-06 16 - 2022-02-06 16) \n IP数量: 1\n CPU: 1核\n
  Lamount | price |  | - | 价格 | 300.00
  Lrel_id | int |  | - | 关联ID | 1
  _currency | array[] | 是 | - | 货币信息 | 
  Lid | int | 是 | - | 货币ID | 1
  Lcode | string | 是 | - | 货币代码 | CNY
  Lprefix | string | 是 | - | 货币前缀 | ￥
  Lsuffix | string | 是 | - | 货币后缀 | 元
  _gateways | array[] |  | - | 支付方式(仅账单状态为未支付Unpaid时返回) | { "name": "WxPay", "title": "微信支付", "url": "upload/pay/WxPay.png", "author_url": "" }
  Lid | int |  | - | 支付方式ID | 1
  Lname | string |  | - | 支付标识 | WxPay
  Ltitle | string |  | - | 支付名称 | 微信支付
  Lurl | string |  | - | 图片地址 | upload/pay/WxPay.png
  Lauthor_url | string | 是 | - | 图片地址base64 | 
  _accounts | array[] |  | - | 交易流水(仅账单已支付返回) | 
  Ltrans_id | int |  | - | 交易流水号 | 17344353453145345
  Lamount_in | price |  | - | 金额 | 
  Lgateway | string |  | - | 支付方式 | 
  Lpay_time | int |  | - | 支付时间 | 

## Knowledgebase.knowledgebase  获取帮助中心
URL: v1/knowledgebase
描述: 获取帮助中心
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 帮助数量 | 1
  _cate | array[] |  | - | 帮助分类 | 
  Lid | int |  | - | 帮助分类ID | 1
  Ltitle | string |  | - | 标题 | example
  Lalias | string |  | - | 别名 | example
  Lcount | int |  | - | 分类帮助数量 | 1
  _knowledgebase | array[] |  | - | 帮助 | 
  Lid | int |  | - | 帮助ID | 1
  Ltitle | string |  | - | 帮助标题 | example
  Lkeywords | string |  | - | 关键字 | example
  Ldescription | string |  | - | 描述 | example
  Lhead_img | string |  | - | 展示图片 | example
  Lread | int |  | - | 阅读量 | 1
  Lcreate_time | int |  | - | 创建时间 | 1
  Lupdate_time | int |  | - | 更新时间 | 1
  Lpush_time | int |  | - | 发布时间 | 1
  Llabel | string |  | - | 标签 | example

## Knowledgebase.knowledgebaseContent  获取帮助中心内容
URL: v1/knowledgebase/:id
描述: 获取帮助中心内容
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int |  | - | 帮助ID | 1
  title | string |  | - | 帮助标题 | example
  keywords | string |  | - | 关键字 | example
  description | string |  | - | 描述 | example
  head_img | string |  | - | 展示图片 | example
  read | int |  | - | 阅读量 | 1
  create_time | int |  | - | 创建时间 | 1
  update_time | int |  | - | 更新时间 | 1
  push_time | int |  | - | 发布时间 | 1
  label | string |  | - | 标签 | example
  content | string |  | - | 内容 | example
  author | string |  | - | 作者 | example
  cate_name | string |  | - | 分类名称 | example
  _next | array[] |  | - | 下一条帮助,如无则为null | 
  Lid | int |  | - | 帮助ID | 1
  Ltitle | string |  | - | 帮助标题 | example
  _prev | array[] |  | - | 上一条帮助,如无则为null | 
  Lid | int |  | - | 帮助ID | 1
  Ltitle | string |  | - | 帮助标题 | example

## Log.apiLog  API日志
URL: v1/log/api
描述: API日志
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 日志数量 | 1
  _log | array[] |  | - | API日志 | 
  Lid | int |  | - | 日志ID | 1
  Ldescription | string |  | - | 描述 | example
  Lip | string |  | - | IP | 192.168.1.1
  Lport | int |  | - | 端口 | 443
  Lcreate_time | int |  | - | 创建时间 | 1648384057
  Luser | string |  | - | 操作人 | example

## Log.loginLog  登录日志
URL: v1/log/login
描述: 登录日志
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 日志数量 | 1
  _log | array[] |  | - | 登录日志 | 
  Lid | int |  | - | 日志ID | 1
  Ldescription | string |  | - | 描述 | example
  Lip | string |  | - | IP | 192.168.1.1
  Lport | int |  | - | 端口 | 443
  Lcreate_time | int |  | - | 创建时间 | 1648384057
  Luser | string |  | - | 操作人 | example

## Log.systemLog  系统日志
URL: v1/log/system
描述: 系统日志
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 日志数量 | 1
  _log | array[] |  | - | 系统日志 | 
  Lid | int |  | - | 日志ID | 1
  Ldescription | string |  | - | 描述 | example
  Lip | string |  | - | IP | 192.168.1.1
  Lport | int |  | - | 端口 | 443
  Lcreate_time | int |  | - | 创建时间 | 1648384057
  Luser | string |  | - | 操作人 | example

## Login.login  登录
URL: /v1/login
描述: 4种登录请求方式，所使用的登录方式必须是在获取的登录方式中有才有效
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _phone | array |  | - | 手机和密码登录 | 
  Lphone_code | string |  | - | 手机区号，不传该参数默认是+86 | 
  Lphone | string | 必填 | - | 手机号 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lpassword | string | 必填 | - | 密码 | 
  _phone_code | array |  | - | 手机和验证码登录 | 
  Lphone_code | string |  | - | 手机区号，不传该参数默认是+86 | 
  Lphone | string | 必填 | - | 手机号 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lcode | string | 必填 | - | 手机验证码 | 
  _email | array |  | - | 邮箱和密码登录 | 
  Lemail | string | 必填 | - | 邮箱 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lpassword | string | 必填 | - | 密码 | 
  _id | array |  | - | 客户id和密码登录 | 
  Lid | string | 必填 | - | 客户的id | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lpassword | string | 必填 | - | 密码 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  jwt | string |  | - | 登录成功，返回登录凭证 | 
  _second_verify | array |  | - | 登录失败，需要二次验证的登录失败，会返回二次验证的数据 | 
  Ltype | string |  | - | 类型 | 
  Lname_zh | string |  | - | 类型名称 | 
  Laccount | string |  | - | 验证账户信息 | 

## Login.loginAPI  API登录
URL: /v1/login_api
描述: 获取支持的登录方式中有API登录方式才可用该接口。
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  account | string | 必填 | - | 手机号或邮箱 | 
  password | string | 必填 | - | API密钥 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  jwt | string |  | - | 登录成功，返回登录凭证 | 

## Login.loginPage  获取支持的登录方式
URL: /v1/login
描述: 获取支持的登录方式，不支持的登录方式则不会返回参数。如果在返回的登录方式中参数captcha的值是1，需要去请求图像验证码接口
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _login_email | array |  | - | 邮箱密码登录 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _login_phone | array |  | - | 手机密码登录 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _login_phone_captcha | array |  | - | 手机验证码登录 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _login_id | array |  | - | ID密码登录 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _sms_country | array |  | - | 允许的手机区号 | 
  Lphone_code | string |  | - | 区号 | 
  Llink | string |  | - | 区号名称 | 
  _oauth | array |  | - | 三方登录，专业版才会有该数据 | 
  Lname | string |  | - | 名称 | 
  Lurl | string |  | - | 授权登录跳转地址 | 
  Limg | string |  | - | 图片 | 

## Login.pwreset  找回密码
URL: /v1/pwreset
描述: 请求方式：POST
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _phone | array |  | - | 手机找回密码 | 
  Lphone_code | string |  | - | 手机区号，不传该参数默认是+86 | 
  Lphone | string | 必填 | - | 手机号 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lcode | string | 必填 | - | 短信验证码 | 
  Lpassword | string | 必填 | - | 密码 | 
  _email | array |  | - | 邮箱找回密码 | 
  Lemail | string | 必填 | - | 邮箱 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lcode | string | 必填 | - | 邮箱验证码 | 
  Lpassword | string | 必填 | - | 密码 | 
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  jwt | string |  | - | 重置密码成功，返回登录凭证 | 

## Login.pwresetPage  获取支持的找回密码方式
URL: /v1/pwreset
描述: 获取支持的找回密码方式，不支持的则不会返回参数。如果在返回中参数captcha的值是1，需要去请求图像验证码接口
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _pwreset_email | array |  | - | 邮箱找回密码 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _pwreset_phone | array |  | - | 手机找回密码 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  _sms_country | array |  | - | 允许的手机区号 | 
  Lphone_code | string |  | - | 区号 | 
  Llink | string |  | - | 区号名称 | 

## Login.register  注册
URL: /v1/register
描述: 所使用的注册方式必须是在获取的注册方式中有才有效
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _phone | array |  | - | 手机注册 | 
  Lphone_code | string | 必填 | - | 手机区号，不传该参数默认是+86 | 
  Lphone | string | 必填 | - | 手机号 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lcode | string | 必填 | - | 短信验证码 | 
  Lpassword | string | 必填 | - | 密码 | 
  Lsale_id | int |  | - | 销售id | 
  Lcustom_fields | array |  | - | 客户自定义字段,示例中的22是字段ID | ["22"=>"百度搜索引擎"]
  Lsystem_fields | array |  | - | 注册时字段，在获取的注册方式中如果该参数是必填，必须要提交 | ["qq"=>"3443551","username"=>"陈诚"]
  _email | array |  | - | 邮箱注册 | 
  Lemail | string | 必填 | - | 邮箱 | 
  Lcaptcha | string |  | - | 图形验证码 | 
  Lidtoken | string |  | - | 图形验证码ID | 
  Lcode | string |  | - | 邮箱验证码 | 
  Lpassword | string | 必填 | - | 密码 | 
  Lsale_id | int |  | - | 销售id | 
  Lcustom_fields | array |  | - | 客户自定义字段,示例中的22是字段ID | ["22"=>"百度搜索引擎"]
  Lsystem_fields | array |  | - | 注册时字段，在获取的注册方式中如果该参数是必填，必须要提交 | ["qq"=>"3443551","username"=>"陈诚"]
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  jwt | string |  | - | 注册成功，返回登录凭证 | 

## Login.registerPage  获取支持的注册方式
URL: /v1/register
描述: 获取支持的注册方式，不支持的注册录方式则不会返回参数。如果在返回的注册方式中参数captcha的值是1，需要去请求图像验证码接口
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _register_email | array |  | - | 邮箱注册 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  Lcode | int |  | - | 是否需要发送邮箱验证码注册，1开启,0关闭 | 
  _register_phone | array |  | - | 手机注册 | 
  Lcaptcha | int |  | - | 图形验证码，1开启,0关闭 | 
  Lcode | int |  | - | 是否需要发送短信验证码注册，1开启，短信注册必须发验证码 | 
  _sms_country | array |  | - | 允许的手机区号 | 
  Lphone_code | string |  | - | 区号 | 
  Llink | string |  | - | 区号名称 | 
  _sale | array |  | - | 销售选择，后台设置了注册时选择销售才会输出该参数 | 
  Lid | int |  | - | 销售人员ID | 
  Luser_nickname | string |  | - | 销售人员名称 | 
  Luser_email | string |  | - | 销售人员邮箱 | 
  _custom_fields | array |  | - | 客户自定义字段，后台设置了注册时自定义字段才会输出该参数 | 
  Lid | int |  | - | 销售人员ID | 
  Lfieldname | string |  | - | 字段名称 | 
  Ldescription | string |  | - | 描述 | 
  Lfieldtype | string |  | - | 类型 | 
  Lfieldoptions | string | 只有是下拉类型时，才需要用到此参数 | - | 下拉类型选项 | 
  Lregexpr | string |  | - | 正则表达式验证字符串 | 
  Lrequired | string |  | - | 显示排序 | 
  _system_fields | array |  | - | 注册时选填字段，开启了才会输出此参数 | 
  Lname | string |  | - | 字段名 | 
  Lrequire | string | 值是1时必填 | - |  | 

## Message.deleteMessage  删除消息
URL: v1/message/:id
描述: 删除消息

## Message.message  消息中心
URL: v1/message
描述: 消息中心
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string |  | - | 类型:work_order_message工单消息product_news产品消息on_site_news站内信event_news活动消息 | work_order_message
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 消息数量 | 1
  _message | array[] |  | - | 消息中心 | 
  Lid | int |  | - | 消息ID | 1
  Ltitle | string |  | - | 标题 | example
  Lcontent | string |  | - | 内容 | example
  Lattachment | array[] |  | - | 附件 | ["example"]
  Ltype | string |  | - | 类型:work_order_message工单消息product_news产品消息on_site_news站内信event_news活动消息 | work_order_message
  Lis_market | int |  | - | 营销信息:0否1是 | 0
  Lcreate_time | int |  | - | 创建时间 | 1648384057
  Lread_time | int |  | - | 阅读时间 | 1648384157
  _unread_message | array[] |  | - | 未读消息 | 
  Ltype | string |  | - | 未读消息类型 | work_order_message
  Lcount | int |  | - | 未读消息数量 | 1

## Message.readMessage  阅读消息
URL: v1/message/:id
描述: 阅读消息

## News.news  获取新闻中心
URL: v1/news
描述: 获取新闻中心
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 新闻数量 | 1
  _cate | array[] |  | - | 新闻分类 | 
  Lid | int |  | - | 新闻分类ID | 1
  Ltitle | string |  | - | 标题 | example
  Lalias | string |  | - | 别名 | example
  Lcount | int |  | - | 分类新闻数量 | 1
  _news | array[] |  | - | 新闻 | 
  Lid | int |  | - | 新闻ID | 1
  Ltitle | string |  | - | 新闻标题 | example
  Lkeywords | string |  | - | 关键字 | example
  Ldescription | string |  | - | 描述 | example
  Lhead_img | string |  | - | 展示图片 | example
  Lread | int |  | - | 阅读量 | 1
  Lcreate_time | int |  | - | 创建时间 | 1
  Lupdate_time | int |  | - | 更新时间 | 1
  Lpush_time | int |  | - | 发布时间 | 1
  Llabel | string |  | - | 标签 | example

## News.newsContent  获取新闻中心内容
URL: v1/news/:id
描述: 获取新闻中心内容
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int |  | - | 新闻ID | 1
  title | string |  | - | 新闻标题 | example
  keywords | string |  | - | 关键字 | example
  description | string |  | - | 描述 | example
  head_img | string |  | - | 展示图片 | example
  read | int |  | - | 阅读量 | 1
  create_time | int |  | - | 创建时间 | 1
  update_time | int |  | - | 更新时间 | 1
  push_time | int |  | - | 发布时间 | 1
  label | string |  | - | 标签 | example
  content | string |  | - | 内容 | example
  author | string |  | - | 作者 | example
  cate_name | string |  | - | 分类名称 | example
  _next | array[] |  | - | 下一条新闻,如无则为null | 
  Lid | int |  | - | 新闻ID | 1
  Ltitle | string |  | - | 新闻标题 | example
  _prev | array[] |  | - | 上一条新闻,如无则为null | 
  Lid | int |  | - | 新闻ID | 1
  Ltitle | string |  | - | 新闻标题 | example

## Pay.credit  使用信用额
URL: v1/invoices/:id/credit
描述: 使用信用额
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 账单ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int | 否 | - | 账单ID | 1
  url | string | 是 | - | 跳转地址 | servicedetail?id=619
  hostid | array[] | 否 | - | 产品ID(当状态码为1001时返回) | {619,620}
  状态码 | 描述
  1001 | 支付完成
  400 | 使用信用额失败

## Pay.fund  使用余额
URL: v1/invoices/:id/fund
描述: 使用余额
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 账单ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int | 否 | - | 账单ID:当状态码为200的时候返回 | 1
  url | string | 是 | - | 跳转地址 | servicedetail?id=619
  hostid | array[] | 否 | - | 产品ID(当状态码为1001时返回) | {619,620}
  状态码 | 描述
  200 | 使用余额成功,账单未支付,还需要调支付接口
  1001 | 使用余额成功,且支付完成
  400 | 使用余额失败

## Pay.fundDelete  删除余额
URL: v1/invoices/:id/fund
描述: 删除余额
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 账单ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoiceid | int | 否 | - | 账单ID | 1

## Pay.pay  发起支付
URL: v1/pay
描述: 发起支付
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  payment | string | 是 |  | 支付方式 | WxPay
  invoiceid | int | 是 | 11 | 账单ID | 1000001
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  payment | string | 是 | - | 支付方式 | AliPayDmf
  total | price | 是 | - | 支付金额 | 1601.20
  total_desc | string | 是 | - | 支付金额描述 | 1601.20元
  credit | price | 是 | - | 余额 | 6001.10
  invoiceid | int | 是 | - | 账单ID | 551197
  _pay_html | array[] | 是 | - | 三方支付信息:1.当 `type=url` 时，[data]值为 转换二维码的url地址 由系统自动转换 2.当 `type=insert` 时，[data]值为 第三方支付系统提供的二维码地址 由系统嵌入该二维码 3.当 `type=jump` 时，[data]值为 需要跳转到第三方的支付链接网址 4.当 `type=html` 时，[data]值为 需要提交的html表单 | { "type": "url", "data": "https://qr.alipay.com/bax08708r1xf4c8pd4iy3080" }
  Ltype | string | 是 | - | 类型 | url
  Ldata | string | 是 | - | 数据 | https://qr.alipay.com/bax08708r1xf4c8pd4iy3080
  _gateway_list | array[] | 是 | - | 支持的支付方式 | { "id": 2, "name": "WxPay", "title": "微信支付", "status": 1, "module": "gateways", "url": "upload/pay/WxPay.png", "author_url": "data:image/png;base64,iVBORw0KGgoA……" }
  Lid | int | 是 | - | 支付方式ID | 1
  Lname | string | 是 | - | 支付方式标识 | WxPay
  Ltitle | string | 是 | - | 支付方式名称 | 微信支付
  Lurl | string | 是 | - | 支付方式图标:资源地址(已舍弃) | upload/pay/WxPay.png
  Lauthor_url | base64 | 是 | - | 支付方式图标:base64数据 | data:image/png;base64,iVBORw0KGgoA……
  is_open_shd_credit_limit | int | 是 | - | 是否开启信用额 | 1
  _client | array[] | 否 | - | 客户信息(登录后才有) | { "credit": "6001.10", "credit_limit": "2000.00", "is_open_credit_limit": 1, "currency": 1, "amount_to_be_settled": 93.19, "credit_limit_used": 93.19, "credit_limit_balance": 1906.81 }
  Lcredit | price | 是 | - | 余额 | 1000.00
  Lcredit_limit | price | 是 | - | 信用额 | 2000.00
  Lis_open_credit_limit | int | 是 | - | 客户是否开启信用额:1是,0否 | 1
  Lamount_to_be_settled | price | 是 | - | 客户已结算信用额 | 93.19
  Lcredit_limit_used | price | 是 | - | 客户已用信用额(包括已结算+未支付的) | 93.19
  Lcredit_limit_balance | price | 是 | - | 客户剩余信用额 | 1906.81

## Pay.status  请求支付状态
URL: v1/invoices/:id/status
描述: 请求支付状态
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int | 是 |  | 账单ID | 1
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  url | string | 是 | - | 跳转地址 | servicedetail?id=619
  hid | array[] | 是 | - | 产品ID数组 | [637,678]
  状态码 | 描述
  1000 | 支付成功
  1001 | 支付失败

## Public.captcha  获取图形验证码图片
URL: /v1/captcha
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  img | string | 必填 | - | base64的图片 | 
  idtoken | string | 必填 | - | 提交图片验证码的时候要提交这个参数 | 

## Public.code  获取验证码
URL: /v1/code
描述: 发送手机或邮件验证码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  action | string | 必填 | - | 验证码支持的方式： login_phone_code 手机验证码登录 register_email 邮箱注册 register_phone 手机注册 pwreset_email 邮箱找回密码 pwreset_phone 手机找回密码 second_phone 手机二次验证 second_email 邮箱二次验证 bind_phone 手机绑定 bind_email 邮箱绑定 login_notice_phone 登录短信提醒 login_notice_email 登录邮件提醒 | 
  type | string | 必填 | - | 发送类型有： phone 手机 email 邮箱 | phone
  phone_code | string |  | - | 发送类型是手机号时要传手机区号，不传默认+86 | 
  account | string | 必填 | - | 手机号或者邮箱 | 
  captcha | string | 开启状态验证码必填 | - | 图形验证码，对应的接口开启了才需要 | 
  idtoken | string | 开启状态验证码必填 | - | 获取图形验证码图片时返回的idtoken的值 | 

## Public.gateway  获取支付方式
URL: /v1/gateway
描述: 请求方式：GET
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  name | string |  | - | 名称 | 
  title | string |  | - | 名称 | 
  img | string |  | - | 支付接口图标 | 

## Public.secondVerify  提交二次验证
URL: /v1/second_verify
描述: 提交二次验证,在其它接口返回的数据中提示需要二次验证的，第一步，“获取验证码”接口，第二步，提交用户填的验证码到该接口
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  account | string | 必填 | - | 发送验证码的手机号或者邮箱 | 
  code | string | 必填 | - | 验证码 | 

## Ticket.createTicket  提交工单
URL: /v1/tickets
描述: 提交工单
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  department_id | int | require | - | 部门ID | 1
  title | string | require | - | 标题 | example
  content | string | require | - | 内容 | example
  host_id | int |  | - | 产品ID | 1
  custom_fields | array[] |  | - | 自定义字段:自定义字段ID和值的键值对 | {"1":"example"}
  attachment | string |  | - | 附件 | example
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  id | int |  | - | 工单ID | 1

## Ticket.getOpenTicketPage  工单提交页面
URL: /v1/tickets/page
描述: 工单提交页面
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _host | array[] |  | - | 产品 | [{"id":1,"product_name":"example","name":"example","status":"Active","ip":"192.168.1.1"}]
  Lid | int |  | - | 产品ID | 1
  Lproduct_name | string |  | - | 商品名称 | example
  Lname | string |  | - | 名称 | example
  Lstatus | string |  | - | 状态:Pending待开通,Active已激活,Suspended暂停 | Active
  Lip | string |  | - | IP | 192.168.1.1
  priority | array[] |  | - | 优先度:low低,medium中,high高 | ["low","medium","high"]
  _department | array[] |  | - | 部门 | [{"id":1,"name":"example","custom_fields":[{"id":1,"name":"example","type":"dropdown","description":"example","options":["a","b","c"],"regexpr":"example","required":1}]
  Lid | int |  | - | ID | 1
  Lname | string |  | - | 名称 | example
  L_custom_fields | array[] |  | - | 自定义字段 | [{"id":1,"name":"example","type":"dropdown","description":"example","options":["a","b","c"],"regexpr":"example","required":1}]
  Lid | int |  | - | ID | 1
  Lname | string |  | - | 名称 | example
  Ltype | string |  | - | 类型:dropdown下拉框,password密码,text文本框,tickbox选项框,textarea文本域 | dropdown
  Ldescription | string |  | - | 描述 | example
  Loptions | array[] |  | - | 选项,用于type为dropdown的自定义字段 | ["a","b","c"]
  Lregexpr | string |  | - | 正则验证 | example
  Lrequired | int |  | - | 必填:0否1是 | 1

## Ticket.getTickets  工单列表
URL: /v1/tickets
描述: 工单列表
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  total | int |  | - | 数据条数 | 1
  _list | array[] |  | - | 工单列表 | [{"id":1,"uid":1,"department_id":1,"host_id":1,"name":"example","email":"example","title":"example","content":"example","status":"Active","priority":"high","admin":"example","department_name":"example","product_name":"example","create_time":1639979575,"update_time":1639979575,"last_reply_time":1639979575}]
  Lid | int |  | - | 工单ID | 1
  Luid | int |  | - | 用户ID | 1
  Ldepartment_id | int |  | - | 部门ID | 1
  Lhost_id | int |  | - | 产品ID | 1
  Lname | string |  | - | 姓名 | example
  Lemail | string |  | - | 邮箱 | example
  Ltitle | string |  | - | 标题 | example
  Lcontent | string |  | - | 内容 | example
  Lstatus | string |  | - | 状态 | Active
  Lpriority | string |  | - | 优先级 | high
  Ladmin | string |  | - | 管理员 | example
  Ldepartment_name | string |  | - | 部门名称 | example
  Lproduct_name | string |  | - | 商品名称 | example
  Lcreate_time | int |  | - | 创建时间 | 1639979575
  Lupdate_time | int |  | - | 更新时间 | 1639979575
  Llast_reply_time | int |  | - | 最后回复时间 | 1639979575

## Ticket.replyTicket  回复工单
URL: /v1/tickets/:id/reply
描述: 回复工单
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  content | string | require | - | 内容 | example
  attachment | string |  | - | 附件 | example

## Ticket.ticketDetail  工单详情
URL: /v1/tickets/:id
描述: 工单详情
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _reply | array[] |  | - | 回复 | [{"id":1,"uid":1,"user":"example","description":"example","ipaddr":"192.168.1.1","port":443,"create_time":1639979575}]
  Ltype | string |  | - | 类型:reply回复,ticket合并的工单 | reply
  Lattachment | string |  | - | 附件 | example
  Lcontent | string |  | - | 内容 | example
  Luser_type | string |  | - | 用户类型:user用户,admin管理员 | user
  Luser | string |  | - | 用户 | example
  Lcreate_time | int |  | - | 创建时间 | 1639979575
  _ticket | array[] |  | - | 工单 | {"id":1,"uid":1,"department_id":1,"host_id":1,"name":"example","email":"example","title":"example","content":"example","status":"Active","priority":"high","admin":"example","department_name":"example","product_name":"example","create_time":1639979575,"update_time":1639979575,"last_reply_time":1639979575}
  Lid | int |  | - | 工单ID | 1
  Luid | int |  | - | 用户ID | 1
  Ldepartment_id | int |  | - | 部门ID | 1
  Lhost_id | int |  | - | 产品ID | 1
  Lname | string |  | - | 姓名 | example
  Lemail | string |  | - | 邮箱 | example
  Ltitle | string |  | - | 标题 | example
  Lcontent | string |  | - | 内容 | example
  Lstatus | string |  | - | 状态 | Active
  Lpriority | string |  | - | 优先级 | high
  Ladmin | string |  | - | 管理员 | high
  Ldepartment_name | string |  | - | 部门名称 | example
  Lproduct_name | string |  | - | 商品名称 | example
  Lcreate_time | int |  | - | 创建时间 | 1639979575
  Lupdate_time | int |  | - | 更新时间 | 1639979575
  Llast_reply_time | int |  | - | 最后回复时间 | 1639979575

## User.companyRealNameAuth  企业认证
URL: /v1/real_name_auth/company
描述: 企业认证
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  certifi_type | string | require | - | 认证方式,用选择的认证方式的值 | example
  company_name | string | require | - | 公司名称 | example
  company_organ_code | string | require | - | 公司代码 | example
  real_name | string | require | - | 真实姓名 | 
  card_type | int | require | - | 认证卡号类型:1大陆0其他地区 | 1
  idcard | string | require | - | 证件号码 | example
  phone | string |  | - | 手机号 | example
  bank | string |  | - | 银行卡号 | example
  img_one | string |  | - | 证件图1 | example
  img_two | string |  | - | 证件图2 | example
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoice_id | int |  | - | 账单ID,选择需要支付的认证方式时会返回 | 1

## User.emailBind  邮箱绑定
URL: /v1/email_bind
描述: 邮箱绑定
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  email | string | require | - | 邮箱 | example
  code | string | require | - | 验证码 | example

## User.getUser  获取会员基础资料
URL: /v1/user
描述: 获取会员基础资料
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  _client | array |  | - | 客户资料 | 
  Lemail | string |  | - | 邮箱 | example
  Lphone_code | string |  | - | 手机区号 | +86
  Lphone | string |  | - | 手机号 | example
  Lqq | string |  | - | QQ号 | example
  Lusername | string |  | - | 真实姓名 | example
  Lcompanyname | string |  | - | 公司名称 | example
  Lcountry | string |  | - | 国家 | example
  Lprovince | string |  | - | 省份 | example
  Lcity | string |  | - | 城市 | example
  Lregion | string |  | - | 地区 | example
  Laddress | string |  | - | 地址 | example
  Ldefaultgateway | string |  | - | 默认支付方式 | example
  Lmarketing_emails_opt_in | string |  | - | 接受营销信息，1接收，0不接收 | 1
  Lcredit | string |  | - | 余额 | example
  country | array |  | - | 国家 | example

## User.loginNotice  登录短信/邮件提醒
URL: /v1/login_notice
描述: 登录短信/邮件提醒
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  type | string | require | - | 类型:sms短信,email邮箱 | sms
  status | string | require | - | 状态:1开启,0关闭 | 1
  code | string |  | - | 验证码,状态为关闭时需要 | example

## User.password  修改密码
URL: /v1/password
描述: 修改密码
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  old_password | string | require | - | 原密码 | example
  new_password | string | require | - | 新密码 | example
  captcha | string |  | - | 图形验证码 | 
  idtoken | string |  | - | 提交图片验证码的时候要提交这个参数 | 

## User.personRealNameAuth  个人认证
URL: /v1/real_name_auth/person
描述: 个人认证
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  certifi_type | string | require | - | 认证方式,用选择的认证方式的值 | example
  real_name | string | require | - | 真实姓名 | 
  card_type | int | require | - | 认证卡号类型:1大陆0其他地区 | 1
  idcard | string | require | - | 证件号码 | example
  phone | string |  | - | 手机号 | example
  bank | string |  | - | 银行卡号 | example
  img_one | string |  | - | 证件图1 | example
  img_two | string |  | - | 证件图2 | example
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  invoice_id | int |  | - | 账单ID,选择需要支付的认证方式时会返回 | 1

## User.phoneBind  手机绑定
URL: /v1/phone_bind
描述: 手机绑定
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  phone_code | string | require | - | 手机区号 | example
  phone | string | require | - | 手机号 | example
  code | string | require | - | 验证码 | example

## User.realNameAuth  获取实名认证信息
URL: /v1/real_name_auth
描述: 获取实名认证信息
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  upload | int |  | - | 是否需要上传图片:1上传2不上传 | 1
  _message | array[] |  | - | 认证信息 | 
  Ltype | string |  | - | 认证类型:certifi_person个人认证certifi_company企业认证 | certifi_person
  Lstatus | int |  | - | 认证状态:0未认证1已认证2未通过3待审核4已提交资料 | 1
  Lauth_real_name | string |  | - | 认证真实姓名,已提交实名认证信息时才会返回 | example
  Lauth_card_type | int |  | - | 认证卡号类型:1大陆0其他地区,已提交实名认证信息时才会返回 | 1
  Lauth_card_number | string |  | - | 认证卡号,已提交实名认证信息时才会返回 | NO123456
  Limg_one | string |  | - | 认证图1,已提交实名认证信息时才会返回 | example
  Limg_two | string |  | - | 认证图2,已提交实名认证信息时才会返回 | example
  Limg_three | string |  | - | 认证图3,已提交实名认证信息时才会返回 | example
  Limg_four | string |  | - | 认证图4,已提交实名认证信息时才会返回 | example
  Lcertify_id | string |  | - | 认证证书,已提交实名认证信息时才会返回 | example
  Lauth_fail | string |  | - | 认证失败原因,已提交实名认证信息时才会返回 | example
  Lcreate_time | int |  | - | 认证提交时间,已提交实名认证信息时才会返回 | example
  Lupdate_time | int |  | - | 认证更新时间,已提交实名认证信息时才会返回 | example
  Lphone | string |  | - | 手机,已提交实名认证信息时才会返回 | example
  Lbank | string |  | - | 银行卡号,已提交实名认证信息时才会返回 | example
  Lcustom_fields1 | string |  | - | 自定义字段1,已提交实名认证信息时才会返回 | example
  Lcustom_fields2 | string |  | - | 自定义字段2,已提交实名认证信息时才会返回 | example
  Lcustom_fields3 | string |  | - | 自定义字段3,已提交实名认证信息时才会返回 | example
  Lcustom_fields4 | string |  | - | 自定义字段4,已提交实名认证信息时才会返回 | example
  Lcustom_fields5 | string |  | - | 自定义字段5,已提交实名认证信息时才会返回 | example
  Lcustom_fields6 | string |  | - | 自定义字段6,已提交实名认证信息时才会返回 | example
  Lcustom_fields7 | string |  | - | 自定义字段7,已提交实名认证信息时才会返回 | example
  Lcustom_fields8 | string |  | - | 自定义字段8,已提交实名认证信息时才会返回 | example
  Lcustom_fields9 | string |  | - | 自定义字段9,已提交实名认证信息时才会返回 | example
  Lcustom_fields10 | string |  | - | 自定义字段10,已提交实名认证信息时才会返回 | example
  Lcompany_name | string |  | - | 公司名称,已提交实名认证信息切认证类型为企业认证时才会返回 | example
  Lcompany_organ_code | string |  | - | 公司代码,已提交实名认证信息切认证类型为企业认证时才会返回 | example
  _method | array[] |  | - | 认证方式 | 
  Lname | string |  | - | 名称 | example
  Lvalue | string |  | - | 值 | example
  L_custom_fields | array[] |  | - | 自定义字段 | 
  Ltitle | string |  | - | 标题 | example
  Ltype | string |  | - | 字段类型:text,select等 | text
  Lvalue | string |  | - | 值 | example
  Ltip | string |  | - | 提示 | example
  Lrequired | bool |  | - | 必填 | true
  Lfield | string |  | - | 字段名称 | example
  default | string |  | - | 默认方式名称 | example

## User.realNameAuthStatus  获取实名认证状态
URL: /v1/real_name_auth/status
描述: 获取实名认证状态,用于支付宝实名认证获取结果
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  status | int |  | - | 认证状态,未提交认证时会返回 | 0

## User.securityInfo  获取安全中心资料
URL: /v1/security_info
描述: 获取安全中心资料
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  login_sms_alert | string |  | - | 登录短信提醒，1开启，0未开启 | 1
  login_email_alert | string |  | - | 登录邮箱提醒，1开启，0未开启 | 1
  _sms_country | array |  | - | 允许的手机区号 | 
  Lphone_code | string |  | - | 区号 | +86
  Llink | string |  | - | 区号名称 | example
  _oauth | array |  | - | 三方登录，专业版才会有该数据 | 
  Lname | string |  | - | 名称 | example
  Lurl | string |  | - | 授权登录跳转地址 | example
  Limg | string |  | - | 图片 | example

## User.user  修改会员资料
URL: /v1/user
描述: 修改会员资料
  参数 | 类型 | 验证规则 | 最大长度 | 描述 | 示例
  qq | string |  | - | QQ号 | example
  username | string |  | - | 真实姓名 | example
  companyname | string |  | - | 公司名称 | example
  country | string |  | - | 国家 | example
  province | string |  | - | 省份 | example
  city | string |  | - | 城市 | example
  region | string |  | - | 地区 | example
  address | string |  | - | 地址 | example
  defaultgateway | string |  | - | 默认支付方式 | example
  marketing_emails_opt_in | string |  | - | 接受营销信息，1接收，0不接收 | 1