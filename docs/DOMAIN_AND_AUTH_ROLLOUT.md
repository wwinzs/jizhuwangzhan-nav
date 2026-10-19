# 记住网站：正式域名与私有账号分阶段上线

## 已确认的域名规划

| 用途 | 域名 | 运行位置 |
|---|---|---|
| 导航前端 | `https://nav.wwinzs.cn` | GitHub Pages（初期） |
| 认证和同步 API | `https://api-nav.wwinzs.cn` | 自有 VPS 的 PHP-FPM + MySQL |
| GitHub 仓库 | `wwinzs/jizhuwangzhan-nav` | 开发、代码审查及 CI |

**当前未执行域名解析、证书签发、服务器安装或数据库初始化。** 此配置方案不自动修改域名或服务器。现有 GitHub Pages 导航继续公开可用。

## 部署顺序与开通门禁

1. 备份当前浏览器的数据，保留 GitHub Pages 原页面可访问。检查 DNS 提供商控制台中的同名记录，避免覆盖正在使用的记录。
2. 前端 DNS 使用 `nav` 子域名的 CNAME 指向 `wwinzs.github.io`，并在 GitHub 仓库 **Settings → Pages → Custom domain** 指定 `nav.wwinzs.cn`，等待平台验证后启用 HTTPS。**此阶段不要提前提交 CNAME 文件或改变线上域名。**
3. 后端 DNS 为 `api-nav` 设置 A 记录指向已确认的 VPS 公网 IPv4（如使用 IPv6 再单独处理 AAAA）。在宝塔新建独立 PHP 站点，文档根必须为 `server/public/`，其他源文件均不得由 Web 直接访问。
4. 配置 `api-nav.wwinzs.cn` 的有效 HTTPS 证书，PHP 必须能检测到 HTTPS。后端 `server/config.local.php` 设置 `frontend_origin='https://nav.wwinzs.cn'` 和 `require_https=true`。正式数据库应独立创建，最小授权，并定期离机备份。
5. 初始化表结构与单一站长账号后，在隔离环境测试 `GET /health`、登录、账号状态、登出、限流、非法 Origin 和 Cookie 配置。不要在公开日志中输出密码、令牌或生产配置。
6. **全部测试成功后**，再把 `auth-client.js` 中的 `initialize({enabled:false})` 切换为 `initialize({enabled:true})`，通过独立 PR 审查发布账号入口。登录期间原有本地收藏不会清空，服务端当前还不会接收或同步收藏。
7. 后续再分版本实现 SMTP 验证、密码重置、设备会话、安全审计、远程文档数据迁移和冲突解决。满足小范围邀请用户的安全条件后才允许站长创建邀请。

## 为什么使用两个子域名

`nav.wwinzs.cn` 与 `api-nav.wwinzs.cn` 是**同站点、不同来源**。跨来源 fetch 必须使用 `credentials:'include'`；服务器仅对精确匹配的 HTTPS 前端 Origin 返回允许凭据的 CORS 响应，不可使用 `*`。站长会话由 API 子域独有的 `__Host-` 前缀安全 Cookie 承载，不暴露在浏览器 JavaScript 中。

`SameSite=Strict` 在两个同站点子域间可用，但不能用 Github 的 `github.io` 预览页来假设同样的登录行为。应针对真实 HTTPS 正式域名验收浏览器会话。未来若需要独立预览域名，应为预览后端和会话隔离环境，不能让测试页面共享正式账户。

## 回滚及安全底线

- 账号 API 不是静态文件托管服务，**不能部署到 GitHub Pages**。
- 禁止将 `server/config.local.php`、MySQL 密码、SMTP 密钥、真实 session token 或生产备份提交到仓库。
- 前端账号入口采用显式关闭的功能开关，在 API 未验证前不会自动向不存在的接口发请求。
- 若账号 API 故障，首页本地收藏继续可用。关闭账号入口不应修改任何收藏数据。
- DNS/自定义域名如果出现问题，先恢复指向而非清空本地数据；账号数据库回滚必须另行备份、验证。
- 公开注册保持关闭，未来的小范围访问采取站长邀请。**仅有账号登录不代表已具有多设备数据同步。**
