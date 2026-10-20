# 宝塔 Debian 单站点部署（v1.9 Draft）

目标：在现有 VPS 上单独部署 `api-nav.wwinzs.cn` 账号 API，不改 `nav.wwinzs.cn`，不启用公开注册、网页登录或云同步。

## 一次性前置条件（已在 VPS 核查）

- Nginx 的网站 root 必须是 `/www/wwwroot/api-nav.wwinzs.cn/server/public`，不能是其上级目录。
- 已有 HTTPS、PHP 8.2 FPM（`/tmp/php-cgi-82.sock`）、MySQL 8。
- 新站点 `server/public/.user.ini` 应限制为 `open_basedir=/www/wwwroot/api-nav.wwinzs.cn/server/:/tmp/`。注意这个 .user.ini 的实际生效还需安装后通过 PHP-FPM 检查。
- Nginx 已添加仅针对 API 站点的点文件隐藏规则；本机测试 `/.user.ini` 必须返回 403/404。
- `location / { try_files $uri $uri/ /index.php$is_args$args; }` 转发到统一入口，并已真实测试通过。
- 宝塔创建 **全新空的** MySQL 8 数据库和专用账号，字符集 utf8mb4。应用账号应只对这一个数据库具有初始化用的建表和增删改查权限，严禁共用现有项目数据库/账号；初始化后可再收紧为运行时 CRUD 所需权限。

## 操作

1. 在宝塔创建并记下新数据库名、数据库专用用户名、密码（不要发给聊天助手或提交 Git）。
2. 从 GitHub CI 的对应审核提交取得 `nav-v1.9-api-baota-draft.zip`，上传到 `/www/wwwroot/api-nav.wwinzs.cn/`。**仅能使用通过 CI 并经过核查的包**；包内必须有 `server/public/index.php`、`server/src/`、`server/scripts/install-baota.php`、`server/schema.sql`、`server/migrations/001_security_events.sql`、`server/migrations/002_password_reset_tokens.sql`；绝不能包含真实 `config.local.php`。
3. SSH（root）执行：
   ```bash
   set -e
   BASE=/www/wwwroot/api-nav.wwinzs.cn
   ZIP="$BASE/nav-v1.9-api-baota-draft.zip"
   test -s "$ZIP"
   unzip -tq "$ZIP"
   test ! -e "$BASE/server/config.local.php"
   unzip -n "$ZIP" -d "$BASE"
   test -f "$BASE/server/public/index.php"
   /www/server/php/82/bin/php "$BASE/server/scripts/install-baota.php"
   ```
   安装程序将用**中文**依次提示：数据库名称、专用数据库用户名、数据库密码、站长邮箱、站长密码、确认站长密码。密码输入时不会回显，也不会作为命令行参数或输出内容出现。成功后显示「安装成功：已建立 7 张独立数据表、1 个站长账号，配置文件权限为 0600。」**安装程序拒绝覆盖已有配置、拒绝向非空数据库写入、拒绝不是预期 VPS 目录的安装**。
4. 仅在安装成功后，通过本机 HTTPS 校验（不要输出登录密码/Cookie 到聊天）：
   ```bash
   curl --noproxy '*' --resolve api-nav.wwinzs.cn:443:127.0.0.1 \
     -sS -i https://api-nav.wwinzs.cn/health
   ```
   预期 HTTP 200 JSON `{"status":"ok"}`；仅凭健康检查成功，不代表登录和跨域验证完成。

5. **仅在 API 已成功安装且 /health 通过后**，从交互式 SSH 终端运行登录闭环检查（不需要重新安装、不修改 Nginx 或其他网站）：
   ```bash
   bash /www/wwwroot/api-nav.wwinzs.cn/server/scripts/check-auth-baota.sh
   ```
   该脚本读取站长邮箱和不回显的密码，通过本机 HTTPS 完成登录 → 获取账号 → 退出 → 确认旧会话被撤销；Cookie 暂存在权限受限的临时目录，脚本退出时删除。输入密码与 Cookie 不写到屏幕和命令行参数。若当前已安装的旧 ZIP 中没有此脚本，请从本次通过 CI 的版本中单独取出 **`server/scripts/check-auth-baota.sh`** 放在同一路径，**不要为此重新执行安装程序，也不要覆盖生产配置**。
   注意：此项只验收本机 HTTPS、登录与会话；浏览器端 CORS/Cookie、PHP-FPM open_basedir、真实公网访问仍须分别验证。

## 失败及回滚

- 安装不会修改 Nginx、DNS、静态导航网站或别的数据库；也不会清除已有数据库。
- MySQL DDL 不能保证事务回滚。若初始化中断，保留专用数据库供核对，**不要重复运行脚本或随意删库**。把不含密码的错误摘要提供给维护者。
- CLI 配置文件 `server/config.local.php` 自动生成并归属 `www:www`，权限 0600，位于 Web 根外，严禁提交 Git、复制至静态站点或放在备份下载目录中。
- PHP 的 `open_basedir` 是额外防护，不代替 Nginx 文档根限制、文件系统权限和应用鉴权。
- `nav.wwinzs.cn` v1.8.1 的静态导航及本地数据完全独立；本 Draft 没有云同步和公开注册，前端登录默认禁用。

## 接口

`GET /health`、`POST /auth/login`、`GET /auth/me`、`POST /auth/logout`。登录和退出请求必须带 `Origin: https://nav.wwinzs.cn`。当前仅有站长账号；邮箱找回、SMTP、邀请机制和历史版本未完成。

## VPS 实机验收记录（2026-10-20）

用户通过腾讯云 Debian VPS 的本机 HTTPS `--resolve api-nav.wwinzs.cn:443:127.0.0.1` 执行了不泄露密码的手工闭环测试，反馈结果：

| 检查 | 结果 |
| --- | --- |
| 数据库中账号存在且状态 active | 通过 |
| 输入密码与数据库存储哈希一致 | MATCH |
| `POST /auth/login` | HTTP 200 |
| 携带 Cookie 的 `GET /auth/me` | HTTP 200 |
| `POST /auth/logout` | HTTP 200 |
| 用退出前保留的 Cookie 再查 `GET /auth/me` | HTTP 401 |

**范围限制**：以上属于用户报告的 VPS 本机 HTTPS 真实接口验收，不等于浏览器端跨域、Cookie、PHP-FPM open_basedir、域名公网访问已通过。

### VPS HTTPS CORS 预检（2026-10-20）

用户从 VPS 以本机 `--resolve` 向 `/auth/login` 发起 OPTIONS 请求，`Origin: https://nav.wwinzs.cn`、`Access-Control-Request-Method: POST`、`Access-Control-Request-Headers: content-type`。报告响应：

- HTTP/2 204；
- `Access-Control-Allow-Origin: https://nav.wwinzs.cn`；
- `Access-Control-Allow-Credentials: true`；
- `Access-Control-Allow-Methods: GET, POST, OPTIONS`；
- `Access-Control-Allow-Headers: Content-Type`；
- `Vary: Origin`；
- `Strict-Transport-Security` 出现两次（排查 Nginx 与 PHP 重复设置）。

这确认真实 API 的预检请求成功，但 **尚未完成浏览器发起的跨域 fetch、携带 Cookie 的浏览器登录、真实公网连接验证**。生产前不要仅凭此结果启用 `auth-client.js`。
此前使用手动输入邮箱及 Python 构造 JSON 的脚本返回过 HTTP 401，后来在固定正确邮箱、一次密码输入、PHP 构造 JSON 的闭环中通过。**尚未证明 Python JSON 编码本身有缺陷**，不要据此改动生产鉴权算法。

**待办**：从导航页原点进行真实浏览器 CORS / Cookie 验证，检查 PHP-FPM 的文件隔离、登录失败策略与站点部署安全，完成审核后再另行开启账号前端开关。保持 v1.9 Draft，不自动合并、不更改线上前端开关。

## v1.9 新版本增量升级门禁（仅供评审；当前不执行）
**v1.9.1–v1.9.3 更新：** 新增 `003_async_reset_mail.sql`，开发分支全新安装现为 **7 张表**；已有数据库应在备份恢复验证后，顺序审查 `001_security_events.sql`、`002_password_reset_tokens.sql`、`003_async_reset_mail.sql`，不得重新安装，不得对现有表执行初始化 schema。只有 SMTP、邮件地址所有权、worker 权限和队列积压监控都通过验收，才可显式启用 `password_reset_enabled` 并在生产系统配置 CLI worker。首次上线前绝不能只打开网页开关而未部署/监控 worker。


**更新：新版开发分支还包含 `002_password_reset_tokens.sql`，因此全新安装为 7 张表；旧库必须依次独立审查与执行 `001`、`002` 两个迁移，而非重装。密码重置的公网申请和确认端点仍然不存在，邮件服务尚未配置。**

此前已运行的 VPS 账号数据库有 **4 张表**。新的 Draft 安全审计和重置令牌功能需要第 5、6 张表 `nav_security_events`；绝对不要对现有数据库再次执行 `install-baota.php`，也不要重复执行 `server/schema.sql`。

升级顺序：先获取 API 站点与独立数据库的可验证备份，明确专属库名和用户，再人工审阅 `server/migrations/001_security_events.sql`，检查新表名是否冲突和建表权限。在**原有独立账号数据库**上按顺序执行这三项增量 SQL；核验原有四张表、站长账号及会话均未变化，确认新增三表存在，之后才安排后端代码更新。任何失败须保留现场，不要自行删除表或重装；正式前端登录开关仍关闭。

此版本补充了仅 root/配置文件属主可用的 `server/scripts/recover-owner.php`，但**不要求部署时立即运行**；只有站长确实遗忘密码，并在核验数据库迁移和备份后，才在交互式 SSH 终端中按提示执行。代码没有 SMTP、邮件确认或公网找回入口。不要将真实密码、Cookie、数据库 DSN 或配置发送到聊天。
