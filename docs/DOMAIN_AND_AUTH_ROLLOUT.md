# 记住网站：大陆 VPS 正式部署方案（2026-10-20 确认）

## 决策及边界

用户已确认 VPS 位于腾讯云中国大陆地域，域名 `wwinzs.cn` 的权威 DNS 在腾讯云 DNSPod。为提升中国大陆用户访问导航页的稳定性，**正式前后端均部署到现有大陆 VPS，仍分离两个 HTTPS 子域名和独立网站目录**。

| 用途 | HTTPS 域名 | DNSPod 预定记录 | 部署位置 |
| --- | --- | --- | --- |
| 导航前端 | `nav.wwinzs.cn` | A → VPS 已核实的公网 IPv4 | 独立 Nginx 静态站点 |
| 私有账号与同步 API | `api-nav.wwinzs.cn` | A → 同一 VPS 公网 IPv4 | 独立 PHP-FPM 站点及 MySQL 数据库 |
| 代码与测试预览 | `wwinzs.github.io/jizhuwangzhan-nav/` | 原 GitHub Pages 保留 | GitHub Actions + Pages |

**不再将 `nav.wwinzs.cn` CNAME 指向 GitHub Pages。** 保留现有 Pages 地址用于独立预览，不要给该 GitHub Pages 仓库配置 `nav.wwinzs.cn` 自定义域名。

本次仅更新草案。**未修改 DNS、备案、服务器、防火墙、数据库、正式证书、站点设置或当前前端功能开关；账号 PR 保持 Draft。**

## 阶段 0：备案和现有环境清点（只读，不修改）

1. 访问腾讯云 ICP 备案控制台或工信部备案查询，核实 `wwinzs.cn` 的真实备案状态、主体、接入商与域名是否一致。腾讯云规则：大陆服务器域名须先 ICP 备案；已经在腾讯云备案的二级域名，其下三级域名一般无需再独立备案。若域名备案在别家云，先核实腾讯云新增接入备案要求；若无备案，先申请，完成前不把子域名解析到大陆 VPS 对外提供网站服务。
2. DNSPod **只查看** `nav`、`api-nav` 是否有现有 A/AAAA/CNAME 记录，以及 `*` 泛解析。不要修改其他记录。
3. 腾讯云控制台只查看实例剩余资源、地域、系统、运行时状态和安全组 80/443；不得直接停用/重启现有服务。
4. VPS 只读采集 PHP、MySQL、Nginx、空闲磁盘/内存、80/443 监听状态和已占用域名。不要把含密码的数据库配置、API key、证书私钥或带有 Cookie 的日志发到聊天。
5. 在进行任何写操作之前，对宝塔站点配置、Nginx 配置和已有数据库执行可验证的备份。

## 阶段 1：部署静态页面（审核后执行）

- 在宝塔新建 `nav.wwinzs.cn` 独立**纯静态**站点，专用目录例如 `/www/wwwroot/nav.wwinzs.cn`。仅通过新增独立虚拟主机配置管理这个域名，不改变 `travel.wwinzs.cn`、`cs.wwinzs.cn` 或任何现有站点。
- 前端构建产物来自同一已验证 GitHub `main` 提交（`index.html`、JS/CSS、图标等）；初期手动部署和校验 checksum，之后再单独制定最低权限自动发布。**不在静态目录放后端配置文件。**
- 正式切换 DNS 前，用本机 hosts / HTTP Host 头在隔离客户端测试静态页面，不预先占用其他虚拟主机。检查打开速度、图片 CDN 与 favicon 失败降级、移动端响应。
- 用 DNSPod A 记录将 `nav` 指向公网 IPv4（默认线路，禁止盲目覆盖已存在的记录）；申请配置该站点独立 HTTPS 证书，完成强制 HTTPS 和 404/缓存验证。
- 保留 GitHub Pages 预览，遇到故障只回滚新站点和 DNS，不停止其他项目。

## 阶段 2：独立账号 API（暂时不执行）

- 宝塔新建 `api-nav.wwinzs.cn` 独立 PHP 站点；文件目录例如 `/www/wwwroot/api-nav.wwinzs.cn/server/`，**Web 文档根严格限制在 `server/public/`**，令源码、密钥和配置文件位于 Web 根外，公开路径不得直接访问。
- 新建隔离的 MySQL 数据库、最小权限用户、独立备份及恢复演练。配置 `server/config.local.php` 于 Web 根外，专用密钥不得提交 Git。
- PHP 8.2+、pdo_mysql 与 Argon2id 检查，TLS 终止后的 `HTTPS` 标志和站点 Origin 精确匹配：`https://nav.wwinzs.cn`。确保 `__Host-nav_session` Cookie 为 Secure/HttpOnly/SameSite=Strict。
- 先使用单站长账号、拒绝公开注册；校验 MySQL 用户隔离、限流、登录失效、退出、错误日志敏感信息和备份恢复。**当前 v1.9 仍缺邮箱验证、密码重置等内容，不可因基础测试通过就宣称全功能账号上线。**

## 阶段 3：安全启用与后续同步

- 必须在两个真实域名上完成 HTTPS、CORS/Origin、Cookie 实机测试，才能通过独立 PR 将 `auth-client.js` 的登录开关从默认关闭切换为开启。
- 登录与退出均不能修改用户当前本地收藏；云端迁移、跨设备冲突、离线修改、版本快照和恢复须在 v2.0 单独验收。
- 将来邀请少量用户时仅允许站长邀请，服务端按 user_id 严格隔离数据，不预先开放公众注册。

## 备案与上线后义务

- 腾讯云备案规则见：https://cloud.tencent.com/document/product/243/19630 。是否可以沿用主域名备案，以备案主体、接入商及当前管局审核要求为准；不可从 DNS 托管服务商推定已经备案。
- 网站对外正式开通后，按适用规定在 **30 日内**办理公安联网备案并按要求展示备案信息；仅提供 API 接口且无面向用户网页内容的服务，官方列有可暂不办理的情形。参考：https://cloud.tencent.com/document/product/243/19142
- DNSPod A 记录帮助：https://docs.dnspod.com/dns/a-record-zh/ 。正式记录设置前检查是否冲突，不要复制未经核实的示例 IP。

## 安全回滚与不影响现有网站的门禁

1. 每一步仅新增 `nav`、`api-nav` 的独立目录、虚拟主机与数据库，不修改现有其他项目目录或复用其数据库。
2. 任何 Nginx/PHP 配置操作都必须先备份、语法测试，再执行**平滑 reload 而非 restart**；配置错误立即回滚，其他站点需继续正常。
3. 仅申请新的证书，不覆盖现有证书和站点默认虚拟主机；不修改原有 `wwinzs.cn` / 其他子域名的 DNS 解析记录。
4. DNS 切换、生产站点创建、证书签发、CLI 站长账号创建和数据库迁移均是独立的**后续明确操作**，不得从本文档自动触发。
5. 原 Pages 预览及用户手动导出的 JSON 数据是回退保障；**生产前需额外备份并验证可恢复性**。
