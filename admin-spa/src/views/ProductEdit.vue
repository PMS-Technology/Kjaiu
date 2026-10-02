<template>
    <div class="page-card" v-loading="loading">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="详 情" name="detail">
                <el-form :model="form" label-width="200px" class="edit-form">
                    <el-form-item label="会员中心导航分类">
                        <el-select v-model="form.ptype" clearable>
                            <el-option v-for="item in navTypes" :key="item.id" :label="item.name" :value="item.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="商品名称">
                        <el-input v-model="form.name" />
                    </el-form-item>
                    <el-form-item label="商品类型">
                        <el-select v-model="form.type" :disabled="isResource === 1 || upstreamType === 'zjmf_api'">
                            <el-option v-for="(label, value) in productTypes" :key="value" :label="label" :value="value" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="商品组">
                        <el-select v-model="form.gid" filterable>
                            <el-option v-for="group in groups" :key="group.id" :label="group.name" :value="group.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="商品描述">
                        <el-input v-model="form.description" type="textarea" :rows="4" placeholder="支持 HTML" />
                    </el-form-item>

                    <el-divider content-position="left">主机名设置</el-divider>
                    <el-form-item label="主机名">
                        <el-switch v-model="form.host_show" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="前缀">
                        <el-input v-model="form.host_prefix" />
                    </el-form-item>
                    <el-form-item label="允许的字符串">
                        <el-input v-model="form.host_rule_num" />
                    </el-form-item>
                    <el-form-item label="主机名长度">
                        <el-input-number v-model="form.host_rule_len_num" :min="1" />
                    </el-form-item>

                    <el-divider content-position="left">密码设置</el-divider>
                    <el-form-item label="密码">
                        <el-switch v-model="form.password_show" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="默认密码长度">
                        <el-input-number v-model="form.password_rule_len_num" :min="6" />
                    </el-form-item>
                    <el-form-item label="密码规则">
                        <el-checkbox v-model="form.password_rule_upper" :true-value="1" :false-value="0">大写字母</el-checkbox>
                        <el-checkbox v-model="form.password_rule_lower" :true-value="1" :false-value="0">小写字母</el-checkbox>
                        <el-checkbox v-model="form.password_rule_num" :true-value="1" :false-value="0">数字</el-checkbox>
                        <el-checkbox v-model="form.password_rule_special" :true-value="1" :false-value="0">特殊字符</el-checkbox>
                    </el-form-item>

                    <el-divider content-position="left">其他设置</el-divider>
                    <el-form-item label="商品开通邮件">
                        <el-select v-model="form.welcome_email" clearable>
                            <el-option v-for="email in emails" :key="email.id" :label="email.name" :value="email.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="库存控制">
                        <el-switch v-model="form.stock_control" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="库存数量">
                        <el-input-number v-model="form.qty" :min="0" />
                    </el-form-item>
                    <el-form-item label="是否允许购买多个">
                        <el-switch v-model="form.allow_qty" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="是否显示特性">
                        <el-switch v-model="form.is_featured" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="是否隐藏">
                        <el-switch v-model="form.hidden" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="购买是否需要实名">
                        <el-switch v-model="form.is_truename" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="是否下架">
                        <el-switch v-model="form.retired" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="购买是否需要绑定手机">
                        <el-switch v-model="form.is_bind_phone" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="限制单个客户购买该商品的订购数量">
                        <el-input-number v-model="form.clientscount" :min="0" />
                    </el-form-item>
                    <el-form-item label="商品数量计算规则">
                        <el-select v-model="form.clientscount_rule" clearable>
                            <el-option label="含未付款订单" :value="0" />
                            <el-option label="仅已开通" :value="1" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="客户是否可以申请停用">
                        <el-switch v-model="form.cancel_control" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="商品删除通知邮件">
                        <el-select v-model="form.auto_terminate_email" clearable>
                            <el-option v-for="email in emails" :key="email.id" :label="email.name" :value="email.id" />
                        </el-select>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="定 价" name="pricing">
                <el-alert
                    type="info"
                    :closable="false"
                    title="价格为 -1 表示该周期不对外销售。初装费仅一次性收取。"
                    style="margin-bottom: 14px"
                />

                <el-table :data="cycleRows" size="small" border>
                    <el-table-column prop="label" label="周期" width="120" />
                    <el-table-column label="价格成本" width="160">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="row.cost"
                                size="small"
                                :precision="2"
                                :controls="false"
                                style="width: 140px"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="价格" width="170">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="row.price"
                                size="small"
                                :precision="2"
                                :controls="false"
                                style="width: 150px"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="初装费成本" width="160">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="row.costSetup"
                                size="small"
                                :precision="2"
                                :controls="false"
                                style="width: 140px"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="初装费" width="170">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="row.setup"
                                size="small"
                                :precision="2"
                                :controls="false"
                                style="width: 150px"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="140">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="resetPrice(row)">重置</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <el-form label-width="160px" style="margin-top: 16px">
                    <el-form-item label="价格方案">
                        <el-radio-group v-model="form.upstream_price_type">
                            <el-radio value="percent">百分比</el-radio>
                            <el-radio value="custom">自定义</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item v-if="form.upstream_price_type === 'percent'" label="百分比">
                        <el-input-number v-model="form.upstream_price_value" :precision="2" :controls="false" />
                        <span class="hint">100 表示与上游同价</span>
                    </el-form-item>
                    <el-form-item label="代理商折扣（只读）">
                        <el-input :model-value="flag.bates" disabled />
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="试 用" name="trial">
                <el-form :model="form" label-width="220px" class="edit-form">
                    <el-form-item label="是否允许试用">
                        <el-switch v-model="form.pay_ontrial_status" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="试用条件">
                        <el-checkbox-group v-model="trialConditions">
                            <el-checkbox :value="1">需要实名认证</el-checkbox>
                            <el-checkbox :value="2">需要绑定手机</el-checkbox>
                            <el-checkbox :value="3">新注册用户</el-checkbox>
                        </el-checkbox-group>
                    </el-form-item>
                    <el-form-item label="试用时长">
                        <el-input-number v-model="form.pay_ontrial_cycle" :min="0" />
                        <el-radio-group v-model="form.pay_ontrial_cycle_type" style="margin-left: 12px">
                            <el-radio value="day">天</el-radio>
                            <el-radio value="hour">小时</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item label="单个账户最大可试用数量">
                        <el-input-number v-model="form.pay_ontrial_num" :min="0" />
                    </el-form-item>
                    <el-form-item label="试用数量计算规则">
                        <el-select v-model="form.pay_ontrial_num_rule" clearable>
                            <el-option label="包含试用中的产品" :value="0" />
                            <el-option label="仅已完成订单" :value="1" />
                        </el-select>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="自动开通" name="auto">
                <el-form :model="form" label-width="180px" class="edit-form">
                    <el-form-item label="付款类型">
                        <el-radio-group v-model="payTypeName" :disabled="upstreamType === 'zjmf_api' || upstreamType === 'v10'">
                            <el-radio value="free">免费</el-radio>
                            <el-radio value="onetime">一次性</el-radio>
                            <el-radio value="recurring">周期</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item label="付费方式">
                        <el-radio-group v-model="form.pay_method">
                            <el-radio value="prepayment">预付费</el-radio>
                            <el-radio value="postpaid">后付费</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item label="开通方式">
                        <el-select v-model="form.api_type" @change="apiTypeChange">
                            <el-option label="本地模块" value="normal" />
                            <el-option v-for="module in modules" :key="module.value" :label="module.name" :value="module.value" />
                        </el-select>
                        <el-button link type="primary" style="margin-left: 8px" @click="syncApiData">同步接口数据</el-button>
                    </el-form-item>
                    <el-form-item :label="form.api_type === 'normal' ? '接口分组' : '供应商'">
                        <el-select v-model="form.server_group" clearable>
                            <el-option
                                v-for="group in serverGroups"
                                :key="group.id"
                                :label="group.name"
                                :value="group.id"
                            />
                        </el-select>
                    </el-form-item>
                    <el-form-item v-if="form.api_type && form.api_type !== 'normal'" label="供应商商品">
                        <el-select v-model="form.upstream_pid" filterable clearable @change="getUpstreamPrice">
                            <el-option
                                v-for="product in upstreamProducts"
                                :key="product.id"
                                :label="product.name"
                                :value="product.id"
                            />
                        </el-select>
                    </el-form-item>

                    <template v-for="field in moduleConfig" :key="field.name">
                        <el-form-item :label="field.title || field.name">
                            <el-select v-if="field.type === 'select'" v-model="moduleConfigValues[field.name]">
                                <el-option
                                    v-for="(label, value) in field.options"
                                    :key="value"
                                    :label="label"
                                    :value="value"
                                />
                            </el-select>
                            <el-input
                                v-else
                                v-model="moduleConfigValues[field.name]"
                                :type="field.type === 'password' ? 'password' : field.type === 'textarea' ? 'textarea' : 'text'"
                            />
                        </el-form-item>
                    </template>

                    <el-form-item label="开通方式">
                        <el-radio-group v-model="form.auto_setup">
                            <el-radio value="">无</el-radio>
                            <el-radio value="on">下单后自动开通</el-radio>
                            <el-radio value="payment">付款后自动开通</el-radio>
                            <el-radio value="order">审核后自动开通</el-radio>
                        </el-radio-group>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="产品配置" name="config">
                <el-form :model="form" label-width="200px" class="edit-form">
                    <el-form-item label="产品配置">
                        <el-switch v-model="form.config_options_upgrade" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="升级通知邮件">
                        <el-select v-model="form.upgrade_email" clearable>
                            <el-option v-for="email in emails" :key="email.id" :label="email.name" :value="email.id" />
                        </el-select>
                    </el-form-item>
                </el-form>

                <h3 class="block-title">已关联的配置项组</h3>
                <el-table :data="configLinks" size="small" border empty-text="暂无数据">
                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="name" label="组名" min-width="180" />
                    <el-table-column prop="description" label="描述" min-width="200" />
                    <el-table-column label="操作" width="120">
                        <template #default="{ row }">
                            <el-button link type="danger" size="small" @click="unlinkConfig(row)">移除</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <el-button type="primary" style="margin-top: 12px" @click="openConfigGroupDialog">关联配置项组</el-button>

                <h3 class="block-title">已有配置项</h3>
                <el-table :data="configOptions" size="small" border empty-text="暂无数据">
                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="gid" label="分组ID" width="90" align="center" />
                    <el-table-column prop="option_name" label="配置项名称" min-width="180" />
                    <el-table-column prop="option_type" label="配置项类型" width="130" />
                    <el-table-column prop="order" label="排序" width="80" align="center" />
                    <el-table-column label="是否隐藏" width="100" align="center">
                        <template #default="{ row }">{{ Number(row.hidden) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="允许升降级" width="110" align="center">
                        <template #default="{ row }">{{ Number(row.upgrade) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="应用优惠码" width="110" align="center">
                        <template #default="{ row }">{{ Number(row.is_discount) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="客户分组折扣" width="120" align="center">
                        <template #default="{ row }">{{ Number(row.is_rebate) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                </el-table>
            </el-tab-pane>

            <el-tab-pane label="自定义字段" name="custom">
                <el-table :data="customFields" size="small" border empty-text="暂无数据">
                    <el-table-column prop="fieldname" label="字段名称" min-width="160" />
                    <el-table-column prop="fieldtype" label="字段类型" width="140" />
                    <el-table-column prop="description" label="描述" min-width="160" />
                    <el-table-column prop="regexpr" label="验证" min-width="160" />
                    <el-table-column prop="fieldoptions" label="选项" min-width="160" />
                    <el-table-column prop="sortorder" label="显示排序" width="100" align="center" />
                    <el-table-column label="操作" width="90">
                        <template #default="{ row }">
                            <el-button link type="danger" size="small" @click="removeCustom(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <h3 class="block-title">新增字段</h3>
                <el-form :model="customForm" label-width="140px" class="edit-form">
                    <el-row :gutter="12">
                        <el-col :span="12">
                            <el-form-item label="字段名称">
                                <el-input v-model="customForm.addfieldname" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="字段类型">
                                <el-select v-model="customForm.addfieldtype">
                                    <el-option v-for="(label, value) in customFieldTypes" :key="value" :label="label" :value="value" />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="描述">
                                <el-input v-model="customForm.addcustomfielddesc" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="验证">
                                <el-input v-model="customForm.addregexpr" />
                            </el-form-item>
                        </el-col>
                        <el-col v-if="customForm.addfieldtype === 'dropdown'" :span="12">
                            <el-form-item label="选项">
                                <el-input v-model="customForm.addfieldoptions" placeholder="以英文逗号分隔" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="显示排序">
                                <el-input-number v-model="customForm.addsortorder" :min="0" />
                            </el-form-item>
                        </el-col>
                    </el-row>
                    <el-form-item>
                        <el-button type="primary" @click="addCustom">添加字段</el-button>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="商品推介计划" name="affiliate">
                <el-form :model="form" label-width="200px" class="edit-form">
                    <el-form-item label="是否启用推介">
                        <el-switch v-model="form.affiliate_enabled" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="推介计划比例类型">
                        <el-select v-model="form.affiliate_type">
                            <el-option label="按百分比" :value="1" />
                            <el-option label="固定金额" :value="2" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="推介计划比例">
                        <el-input-number v-model="form.affiliate_bates" :precision="2" :controls="false" />
                    </el-form-item>
                    <el-form-item label="是否开启续费">
                        <el-switch v-model="form.affiliate_renew_type" :active-value="1" :inactive-value="0" />
                    </el-form-item>
                    <el-form-item label="续费方式">
                        <el-select v-model="form.affiliate_renew_type" clearable>
                            <el-option label="按百分比" :value="1" />
                            <el-option label="固定金额" :value="2" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="续费比例">
                        <el-input-number v-model="form.affiliate_renew" :precision="2" :controls="false" />
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="文件下载" name="downloads">
                <el-row :gutter="16">
                    <el-col :md="10">
                        <h3 class="block-title">下载分类</h3>
                        <el-form :model="addClassForm" label-width="100px">
                            <el-form-item label="类别">
                                <el-select v-model="addClassForm.catid" filterable clearable>
                                    <el-option v-for="cat in downloadCats" :key="cat.id" :label="cat.name" :value="cat.id" />
                                </el-select>
                            </el-form-item>
                            <el-form-item label="分类名称">
                                <el-input v-model="addClassForm.title" />
                            </el-form-item>
                            <el-form-item label="分类描述">
                                <el-input v-model="addClassForm.description" type="textarea" :rows="2" />
                            </el-form-item>
                            <el-form-item>
                                <el-button type="primary" @click="submitDownloadCat">保存分类</el-button>
                            </el-form-item>
                        </el-form>
                    </el-col>

                    <el-col :md="14">
                        <h3 class="block-title">文件列表</h3>
                        <el-table :data="downloadFiles" size="small" border empty-text="暂无数据">
                            <el-table-column prop="id" label="ID" width="70" align="center" />
                            <el-table-column prop="title" label="文件名称" min-width="180" />
                            <el-table-column prop="description" label="文件描述" min-width="180" />
                            <el-table-column prop="category" label="类别" width="100" align="center" />
                            <el-table-column label="操作" width="90">
                                <template #default="{ row }">
                                    <el-button link type="danger" size="small" @click="removeDownload(row)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>

                        <el-form :model="uploadForm" label-width="100px" style="margin-top: 14px">
                            <el-form-item label="类别">
                                <el-select v-model="uploadForm.catid" filterable>
                                    <el-option v-for="cat in downloadCats" :key="cat.id" :label="cat.name" :value="cat.id" />
                                </el-select>
                            </el-form-item>
                            <el-form-item label="文件名称">
                                <el-input v-model="uploadForm.title" />
                            </el-form-item>
                            <el-form-item label="文件描述">
                                <el-input v-model="uploadForm.description" type="textarea" :rows="2" />
                            </el-form-item>
                            <el-form-item label="选择文件">
                                <input type="file" @change="onFileChange" />
                            </el-form-item>
                            <el-form-item>
                                <el-button type="primary" @click="submitDownloadFile">上传</el-button>
                            </el-form-item>
                        </el-form>
                    </el-col>
                </el-row>
            </el-tab-pane>

            <el-tab-pane label="链接" name="links">
                <el-form label-width="140px" class="edit-form">
                    <el-form-item label="快速订购链接">
                        <el-input v-model="form.product_shopping_url" readonly>
                            <template #append>
                                <el-button @click="copy(form.product_shopping_url)">复制</el-button>
                            </template>
                        </el-input>
                    </el-form-item>
                    <el-form-item label="产品组链接">
                        <el-input v-model="form.product_group_url" readonly>
                            <template #append>
                                <el-button @click="copy(form.product_group_url)">复制</el-button>
                            </template>
                        </el-input>
                    </el-form-item>
                </el-form>
            </el-tab-pane>
        </el-tabs>

        <div class="footer-actions">
            <el-button @click="$router.push('/products')">返回列表</el-button>
            <el-button type="primary" :loading="saving" @click="save">保存商品</el-button>
        </div>

        <el-dialog v-model="configGroupVisible" title="产品配置项组" width="480px">
            <el-form :model="configGroupForm" label-width="90px">
                <el-form-item label="组名">
                    <el-input v-model="configGroupForm.name" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="configGroupForm.description" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="configGroupVisible = false">取消</el-button>
                <el-button type="primary" @click="submitConfigGroup">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';

import client from '../api/client';
import { pickList } from '../utils/format';

const route = useRoute();
const productId = computed(() => route.params.id);

const loading = ref(false);
const saving = ref(false);
const activeTab = ref('detail');
const isResource = ref(0);
const upstreamType = ref('');

const groups = ref([]);
const navTypes = ref([]);
const modules = ref([]);
const serverGroups = ref([]);
const emails = ref([]);
const configOptions = ref([]);
const configLinks = ref([]);
const customFields = ref([]);
const upstreamProducts = ref([]);
const downloadCats = ref([]);
const downloadFiles = ref([]);
const moduleConfig = ref([]);
const moduleConfigValues = reactive({});
const flag = reactive({ bates: '' });
const trialConditions = ref([]);

const configGroupVisible = ref(false);
const configGroupForm = reactive({ name: '', description: '' });
const addClassForm = reactive({ catid: '', title: '', description: '' });
const uploadForm = reactive({ catid: '', title: '', description: '', file: null });
const customForm = reactive({
    addfieldtype: 'text',
    addfieldname: '',
    addcustomfielddesc: '',
    addregexpr: '',
    addfieldoptions: '',
    addsortorder: 0,
});

const productTypes = ref({
    hostingaccount: '虚拟主机',
    server: '独立服务器',
    cloud: '云服务器',
    dcimcloud: '魔方云',
    dcim: '魔方DCIM',
    software: '软件产品',
    cdn: 'CDN',
    other: '其他服务',
});

const customFieldTypes = {
    text: '单行文本',
    password: '密码',
    textarea: '多行文本',
    dropdown: '下拉选项',
};

// Cycle key -> pricing column mapping. The API stores selling prices in
// `<cycle>` and setup fees in `<abbr>setupfee`.
const cycleMap = [
    { key: 'onetime', label: '一次性', fee: 'osetupfee' },
    { key: 'hour', label: '小时', fee: 'hsetupfee' },
    { key: 'day', label: '日', fee: 'dsetupfee' },
    { key: 'ontrial', label: '试用', fee: 'ontrialfee' },
    { key: 'monthly', label: '月', fee: 'msetupfee' },
    { key: 'quarterly', label: '季', fee: 'qsetupfee' },
    { key: 'semiannually', label: '半年', fee: 'ssetupfee' },
    { key: 'annually', label: '年', fee: 'asetupfee' },
    { key: 'biennially', label: '两年', fee: 'bsetupfee' },
    { key: 'triennially', label: '三年', fee: 'tsetupfee' },
    { key: 'fourly', label: '四年', fee: 'foursetupfee' },
    { key: 'fively', label: '五年', fee: 'fivesetupfee' },
    { key: 'sixly', label: '六年', fee: 'sixsetupfee' },
    { key: 'sevenly', label: '七年', fee: 'sevensetupfee' },
    { key: 'eightly', label: '八年', fee: 'eightsetupfee' },
    { key: 'ninely', label: '九年', fee: 'ninesetupfee' },
    { key: 'tenly', label: '十年', fee: 'tensetupfee' },
];

const form = reactive({
    ptype: '',
    name: '',
    type: 'cloud',
    gid: '',
    description: '',
    host_show: 0,
    host_prefix: '',
    host_rule_num: '',
    host_rule_len_num: 8,
    password_show: 0,
    password_rule_len_num: 8,
    password_rule_upper: 0,
    password_rule_lower: 0,
    password_rule_num: 0,
    password_rule_special: 0,
    welcome_email: '',
    stock_control: 0,
    qty: 0,
    allow_qty: 0,
    is_featured: 0,
    hidden: 0,
    is_truename: 0,
    retired: 0,
    is_bind_phone: 0,
    clientscount: 0,
    clientscount_rule: '',
    cancel_control: 0,
    auto_terminate_email: '',
    pay_type: 'recurring',
    pay_method: 'prepayment',
    api_type: '',
    server_group: '',
    upstream_pid: '',
    upstream_price_type: 'percent',
    upstream_price_value: 100,
    auto_setup: '',
    pay_ontrial_status: 0,
    pay_ontrial_cycle: 0,
    pay_ontrial_cycle_type: 'day',
    pay_ontrial_num: 1,
    pay_ontrial_num_rule: 0,
    config_options_upgrade: 0,
    upgrade_email: '',
    affiliate_enabled: 0,
    affiliate_type: 1,
    affiliate_bates: 0,
    affiliate_renew_type: 1,
    affiliate_renew: 0,
    product_shopping_url: '',
    product_group_url: '',
});

const pricing = ref({});
const payTypeName = computed({
    get: () => form.pay_type,
    set: (value) => {
        form.pay_type = value;
    },
});

const cycleRows = computed(() =>
    cycleMap.map((cycle) => ({
        key: cycle.key,
        label: cycle.label,
        fee: cycle.fee,
        price: Number(pricing.value[cycle.key] ?? -1),
        cost: Number(pricing.value[`cost_${cycle.key}`] ?? 0),
        setup: Number(pricing.value[cycle.fee] ?? 0),
        costSetup: Number(pricing.value[`cost_${cycle.fee}`] ?? 0),
    })),
);

const applyPricing = () => {
    const next = { ...pricing.value };

    cycleRows.value.forEach((row) => {
        next[row.key] = row.price;
        next[row.fee] = row.setup;
        next[`cost_${row.key}`] = row.cost;
        next[`cost_${row.fee}`] = row.costSetup;
    });

    pricing.value = next;
};

const resetPrice = (row) => {
    row.price = -1;
    row.setup = 0;
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get(`edit_product_page/${productId.value}`);
        const data = response.data || {};

        const record = data.product || {};
        Object.keys(form).forEach((key) => {
            if (record[key] !== undefined && record[key] !== null) {
                form[key] = record[key];
            }
        });

        // `pay_type` nests the trial settings in the original payload.
        if (record.pay_type && typeof record.pay_type === 'object') {
            form.pay_type = record.pay_type.pay_type || form.pay_type;
            form.pay_ontrial_status = record.pay_type.pay_ontrial_status ?? 0;
            form.pay_ontrial_cycle = record.pay_type.pay_ontrial_cycle ?? 0;
            form.pay_ontrial_cycle_type = record.pay_type.pay_ontrial_cycle_type || 'day';
            form.pay_ontrial_num = record.pay_type.pay_ontrial_num ?? 1;
            form.pay_ontrial_num_rule = record.pay_type.pay_ontrial_num_rule ?? 0;
            trialConditions.value = record.pay_type.pay_ontrial_condition || [];
        }

        isResource.value = Number(record.is_resource || 0);
        upstreamType.value = record.api_type || '';

        pricing.value = (data.pricing && data.pricing[0]) || {};
        groups.value = data.product_group || [];
        navTypes.value = data.ptype || [];
        modules.value = data.modules || [];
        emails.value = data.email_list || [];
        customFields.value = data.customfields || [];
        configLinks.value = data.config_groups || [];
        configOptions.value = data.config_options || [];
        downloadCats.value = data.hierarchy_cats || [];
        downloadFiles.value = data.download_files || [];

        const serverGroup = data.server_group || {};
        serverGroups.value = serverGroup[form.api_type] || serverGroup.normal || [];
        flag.bates = data.flag && data.flag.bates ? data.flag.bates : '';

        if (data.type) {
            productTypes.value = data.type;
        }

        if (form.product_group_url || form.product_shopping_url) {
            // Already populated from the record.
        }
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const apiTypeChange = async () => {
    try {
        const response = await client.get(`provision/${form.api_type}`);
        moduleConfig.value = response.data.config || response.data || [];
        moduleConfig.value.forEach((field) => {
            moduleConfigValues[field.name] = field.default ?? '';
        });
    } catch {
        moduleConfig.value = [];
    }
};

const getUpstreamPrice = async () => {
    try {
        const response = await client.get('product/get_upstream_price', {
            params: { zjmf_api_id: form.upper_reaches_id || form.zjmf_api_id, pid: form.upstream_pid },
        });
        if (response.data && response.data.price !== undefined) {
            form.upstream_price_value = response.data.price;
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const syncApiData = async () => {
    try {
        await client.post('product/sync_product_info', { id: productId.value });
        ElMessage.success('同步成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openConfigGroupDialog = () => {
    configGroupForm.name = '';
    configGroupForm.description = '';
    configGroupVisible.value = true;
};

const submitConfigGroup = async () => {
    try {
        const endpoint = configGroupForm.id ? 'options/edit_groups_post' : 'options/create_groups_post';
        await client.post(endpoint, { ...configGroupForm, pid: productId.value });
        ElMessage.success('保存成功');
        configGroupVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const unlinkConfig = async (row) => {
    try {
        await client.post('product_del_custom', { pid: productId.value, gid: row.id });
        ElMessage.success('已移除');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const addCustom = async () => {
    if (!customForm.addfieldname.trim()) {
        ElMessage.warning('请输入字段名称');
        return;
    }

    try {
        await client.post('edit_product', {
            id: productId.value,
            addfieldname: customForm.addfieldname,
            addfieldtype: customForm.addfieldtype,
            addcustomfielddesc: customForm.addcustomfielddesc,
            addregexpr: customForm.addregexpr,
            addfieldoptions: customForm.addfieldoptions,
            addsortorder: customForm.addsortorder,
        });
        ElMessage.success('添加成功');
        customForm.addfieldname = '';
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeCustom = async (row) => {
    try {
        await client.post('product_del_custom', { pid: productId.value, id: row.id });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const onFileChange = (event) => {
    uploadForm.file = event.target.files[0] || null;
};

const submitDownloadCat = async () => {
    try {
        await client.post('product_downloadcats', { ...addClassForm, productid: productId.value });
        ElMessage.success('保存成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitDownloadFile = async () => {
    const payload = new FormData();
    payload.append('catid', uploadForm.catid);
    payload.append('title', uploadForm.title);
    payload.append('description', uploadForm.description);
    payload.append('productid', productId.value);

    if (uploadForm.file) {
        payload.append('file', uploadForm.file);
    }

    try {
        await client.post('product_manage_downloads', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        ElMessage.success('上传成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeDownload = async (row) => {
    try {
        await client.post('product_manage_downloads', { id: row.id, del: 1 });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const copy = async (value) => {
    try {
        await navigator.clipboard.writeText(value || '');
        ElMessage.success('已复制');
    } catch {
        ElMessage.error('复制失败，请手动选择文本');
    }
};

const save = async () => {
    applyPricing();
    saving.value = true;

    try {
        await client.post('edit_product', {
            id: productId.value,
            ...form,
            pricing: pricing.value,
            module_config: { ...moduleConfigValues },
            pay_ontrial_condition: trialConditions.value,
        });
        ElMessage.success('保存成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<style scoped>
.edit-form {
    max-width: 1000px;
    margin-top: 8px;
}

.block-title {
    font-size: 14px;
    margin: 18px 0 10px;
}

.footer-actions {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.hint {
    margin-left: 10px;
    color: #9ca3af;
    font-size: 12px;
}
</style>
