# 记住网站 v1.9 — 私有账号服务端（第一阶段 / Draft）

**状态：未部署，不代表账号功能已经上线。** 当前包含私有账号的基础服务端，以及默认关闭的可选前端登录界面。不包含公开注册、邀请注册、邮件验证、找回密码、设备管理 UI 或云端数据同步。

## 安全原则
- 默认没有公开注册接口。站长账号只能通过服务器 CLI 创建，且同一安装只创建一位 owner。
- 密码使用 PHP Argon2id 散列，登录返回通用失败信息；按 IP 与邮箱做 15 分钟窗口的登录限流。
- 会话使用 256 位随机令牌，数据库只存 SHA-256 散列；Cookie 采用 HttpOnly、Secure、SameSite=Strict、Host-only (__Host-)。
- 登录和退出要求 **精确匹配 frontend_origin 的 Origin**，拒绝跨站表单提交和其他来源。配置文件存于 public/ 外。
- 计划使用正式域名 `https://nav.wwinzs.cn` 与 `https://api-nav.wwinzs.cn`，**两个域名均部署在中国大陆腾讯云 VPS 的独立 Nginx 虚拟主机**，GitHub Pages 继续用于不带登录的预览；详见 `docs/DOMAIN_AND_AUTH_ROLLOUT.md`。跨源会话只在正式同站点 HTTPS 域名验收，`github.io` 预览页不能作为替代。
- 后台不能以项目源码目录作为文档根目录，必须将站点根目录指向 server/public/；禁止直接暴露 config.local.php 或 SQL 文件。

## 服务器准备（待正式部署前执行）
1. **先确认 ICP 备案及腾讯云接入备案状态**，仅在正式允许对外提供网站服务后进行 DNS 发布。检查现有 VPS 资源和站点配置，不覆盖旧网站。需要时安装 PHP 8.2+（pdo_mysql、openssl、mbstring）和 MySQL 8.0+，创建独立数据库和最低权限的应用账号。
2. 将 server/ 放置在 API 独立站点目录中，**Web 文档根仅**指向 server/public/，而 server/config.local.php、server/schema.sql 位于 Web 根之外；PHP-FPM 必须能读取受限配置。导航静态前端另建独立站点 `nav.wwinzs.cn`。
3. 复制 server/config.local.example.php 为 server/config.local.php；填入真实数据库配置、前端 HTTPS Origin，并运行 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 生成 app_key。
4. 创建数据表：`mysql -u nav_only -p jizhu_nav < server/schema.sql`（部署时应用数据库账号需被授予建表权限，之后可收紧）。
5. 在服务器交互终端执行 `php server/scripts/create-owner.php owner@example.com`，输入两次至少 14 字节的强密码（终端不回显）。
6. 后续用HTTPS测试 `GET /health`、`POST /auth/login`、`GET /auth/me` 和 `POST /auth/logout`。登入 JSON 示例：
   `{"email":"owner@example.com","password":"你的密码"}`。
   POST 必须带准确的 `Origin: https://nav.wwinzs.cn` 和 `Content-Type: application/json`。

## 当前接口
| 路径 | 方法 | 作用 |
| --- | --- | --- |
| /health | GET | 检测数据库连通性（无秘密信息） |
| /auth/login | POST | 身份校验、限流、签发安全会话 |
| /auth/me | GET | 读取当前登录用户的非敏感字段 |
| /auth/logout | POST | 吊销会话并清除 Cookie |
| /auth/change-password | POST | 校验当前密码、设置新密码，并撤销该账号所有旧会话（仅开发分支） |
| /auth/sessions | GET | 读取当前账号最多 50 条有效会话，当前会话优先；不返回 Cookie、IP 或设备信息（仅开发分支） |
| /auth/sessions/revoke-others | POST | 确认后撤销当前账号的其他有效会话，保留本次登录（仅开发分支） |

## 完成 v1.9 前仍需实现
- HTTPS 域名与 PHP-FPM / Nginx 反代的真实环境验收。
- 邮箱验证、找回密码及会话管理；配置 SMTP 和频率限制、一次性验证码。
- 可选登录的前端界面、状态提示和退出按钮已加入开发分支；**默认关闭**，必须先完成域名、HTTPS、API 真实环境验证，再经独立发布 PR 将功能开关启用。未登录仍使用本地导航。
- 账号隔离、历史审计、会话清理和真实服务端数据接口；这部分属于后续云同步的关键前置条件。
- 多设备数据同步、合并冲突与历史版本将在 v2.0 独立实现。

**切勿把此 Draft 直接部署到生产并宣称完整账号体系。** 配置文件、密码、生产数据库不进入 Git 仓库。

## 为后续同步预留的数据库结构

`nav_user_documents` 以 `user_id` 为主键并通过外键关联用户，提供 `revision` 和 JSON 文档字段，仅为 v2.0 的数据隔离与版本控制预留。当前没有开放该表的读写接口，不应据此认为云同步已完成。

## 本轮真实执行结果

- Private auth backend CI：真实 MySQL 8 初始化、站长账号唯一性、密码散列、登录、Cookie 会话、非法 Origin 拒绝、登出和限流测试通过。
- Validate navigation：保留旧网站的浏览器功能回归，并新增 25 项登录界面、失败反馈、退出及断网降级检查；合计 205 项通过。
- **未连接或更改生产 VPS、数据库、域名 DNS 或 GitHub Pages 的当前正式页面**。

## v1.9 后续账号安全开发（仅 Draft）

- 新增受保护的 `POST /auth/change-password`。请求必须来自精确允许的 `Origin`，携带有效登录 Cookie；JSON 请求体需要 `current_password`、`new_password` 两个字符串。
- 新密码长度为 14–1024 字节，不允许与旧密码相同。每个账号每 15 分钟最多 8 次变更尝试；错误旧密码统一拒绝，达到阈值返回 HTTP 429。
- 成功后在单个数据库事务中更新 Argon2id 密码哈希并撤销该账号全部旧会话（包括本设备），清除当前 Cookie，返回 `{"ok":true,"reauth_required":true}`；用户必须重新登录。并发更新使用旧哈希条件确保不会覆盖其他密码变更。
- CI 使用隔离 MySQL 测试用户验证鉴权、Origin、密码长度、错误旧密码、两个并存旧会话失效及新密码可重新登录。
- 登录状态下的账号弹窗新增“修改登录密码”表单，前端验证新密码长度和两次输入一致；修改成功后清空输入并切换到重新登录提示。浏览器回归测试覆盖失败提示、表单收起、Cookie 请求及本地收藏不变。**正式站仍关闭账号入口，UI 仅供开发分支回归测试。**
- 本次不涉及邮箱验证码、找回密码、SMTP、设备管理 UI、云同步及生产服务器部署；即使 GitHub CI 全绿也不能自动开启网页登录。

## 会话管理与安全清理（v1.9 Draft）

- 登录后的账号弹窗增加“管理登录会话”。只显示 UTC 时间戳对应的本地时间及当前/其他会话标记，不记录或推断设备名称、用户 IP、浏览器指纹。列表最多 50 条，当前会话优先。
- `GET /auth/sessions` 必须携带可信 `Origin` 和有效会话 Cookie，仅查询当前 `user_id`，不返回 token/hash。返回 `{"sessions":[{"created_at":"...Z","expires_at":"...Z","current":true}]}`。
- `POST /auth/sessions/revoke-others` 必须携带可信 `Origin`、有效会话 Cookie、`Content-Type: application/json` 及 `{"confirm":true}`；`{"confirm":false}` 返回 400。仅撤销该账号的其他有效会话，当前会话不被撤销；此动作要求前端二次确认，无法撤销他人账号的会话。
- 新增 `php server/scripts/cleanup-sessions.php` **仅 CLI** 清理到期会话、撤销超过 30 日的会话及超过 1 日的登录限流记录。每类最多清理 500 条，避免长时间占用数据库。**本次没有部署/启用定时任务**；上线后应在单独评审与确认运行环境后制定每日维护计划。
- GitHub CI 使用全新 MySQL 实例验证三份会话并存、非法来源拒绝、明确确认、撤销其他会话、当前会话保留及有效记录未被清理。UI 浏览器测试采用 mock，不代表已通过生产浏览器 Cookie/CORS 实测。
- 无数据库表结构变动，不要求为此次功能重新安装数据库。**代码暂留 Draft，生产 VPS 尚未更新，不能直接对正式 API 调用这些新增接口。**
