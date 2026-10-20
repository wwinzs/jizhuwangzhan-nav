# 记住网站 v1.9 — 私有账号服务端（第一阶段 / Draft）

**状态：本分支新增代码尚未部署；VPS 上已有较早的测试 API，但正式导航的网页登录仍未启用。** 当前包含私有账号基础服务端、默认关闭的可选前端登录界面、密码修改和会话管理。不包含公开注册、邀请注册、邮箱验证、互联网密码找回或云端数据同步。

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
- 邮箱地址所有权、真实 SMTP/发件域名投递、异步队列 worker 的上线与监控以及生产浏览器验收仍需落实。邮件找回的开发版已有后端/UI 和隔离测试，但尚未在正式站开放。
- 可选登录的前端界面、状态提示和退出按钮已加入开发分支；**默认关闭**，必须先完成域名、HTTPS、API 真实环境验证，再经独立发布 PR 将功能开关启用。未登录仍使用本地导航。
- 账号隔离和真实服务端数据接口；目前只实现最小化账号安全审计、CLI 清理和查询，尚未完成全面审计与同步所需的访问控制评审。
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
- 此段描述的是初始账号阶段；后续 Draft 已补充邮箱找回开发版、SMTP 和异步 worker（均未部署），云同步及生产服务器部署仍不在本次范围。

## 会话管理与安全清理（v1.9 Draft）

- 登录后的账号弹窗增加“管理登录会话”。只显示 UTC 时间戳对应的本地时间及当前/其他会话标记，不记录或推断设备名称、用户 IP、浏览器指纹。列表最多 50 条，当前会话优先。
- `GET /auth/sessions` 必须携带可信 `Origin` 和有效会话 Cookie，仅查询当前 `user_id`，不返回 token/hash。返回 `{"sessions":[{"created_at":"...Z","expires_at":"...Z","current":true}]}`。
- `POST /auth/sessions/revoke-others` 必须携带可信 `Origin`、有效会话 Cookie、`Content-Type: application/json` 及 `{"confirm":true}`；`{"confirm":false}` 返回 400。仅撤销该账号的其他有效会话，当前会话不被撤销；此动作要求前端二次确认，无法撤销他人账号的会话。
- 新增 `php server/scripts/cleanup-sessions.php` **仅 CLI** 清理到期会话、撤销超过 30 日的会话及超过 1 日的登录限流记录。每类最多清理 500 条，避免长时间占用数据库。**本次没有部署/启用定时任务**；上线后应在单独评审与确认运行环境后制定每日维护计划。
- GitHub CI 使用全新 MySQL 实例验证三份会话并存、非法来源拒绝、明确确认、撤销其他会话、当前会话保留及有效记录未被清理。UI 浏览器测试采用 mock，不代表已通过生产浏览器 Cookie/CORS 实测。
- 无数据库表结构变动，不要求为此次功能重新安装数据库。**代码暂留 Draft，生产 VPS 尚未更新，不能直接对正式 API 调用这些新增接口。**

## v1.9 安全事件审计与离线站长恢复（仅 Draft）

- **新数据库表：** `server/migrations/001_security_events.sql` 在现有四张表之外增加 `nav_security_events`，仅存 `user_id`、枚举事件类型及 UTC 创建时间；**不存密码、重置口令、Cookie/会话 token、哈希、IP、User-Agent、邮箱、设备指纹**。
- 对成功登录、正常退出、修改密码、撤销其他有效会话、站长终端恢复执行审计；关联的敏感更新与审计写入处于同一事务，审计失败会阻止敏感操作完成。登录失败暂不记录到此表，避免匿名刷入大量事件。
- 仅站长账号可调用 `GET /auth/security-events` 获取自身最近 30 条事件（事件名与时间），要求合法 Origin 和已认证 Cookie。实际显示与验证需等待生产浏览器上线门禁。
- `server/scripts/recover-owner.php` 是**仅限 root 或受保护配置文件所有者的交互式 PHP CLI 工具**，需要在服务器 TTY 中输入站长邮箱、`RECOVER` 明确确认，以及两次不回显的新密码。成功后原子性重置站长密码、撤销全部旧会话并产生 `owner_recovery` 审计事件。**无公开恢复端点，无邮件发信能力，不要把密码传到聊天或放在 shell 参数中**。
- `server/scripts/cleanup-sessions.php` 每次最多删除 500 条超过 90 天的审计记录，并继续按原有界限清理过期会话与限流。未部署或启用后台定时任务。
- **现有 VPS 数据库仍是之前的四表版本时，不要重新运行全新安装器。** 升级需先单独备份且验证可恢复，再审核应用用户的建表权限、在相应独立数据库执行增量迁移 `001_security_events.sql`，核验表结构和数据不变，最后才替换 API 代码；如果尚未具备这些条件，就继续使用原有 API。
- 新版全新安装器会在空库中创建原四表及审计表，预期共五表。迁移 SQL 不是安装脚本，不能用它重建已有数据库。以上更新尚未应用到 VPS 或主分支。

## v1.9 密码重置令牌安全基础（仅内部实现，**未对公网开放**）

- 独立增量迁移 `server/migrations/002_password_reset_tokens.sql`：在完成 `001_security_events.sql` 后新增 `nav_password_reset_tokens` 第 6 张表，安全审计增加 `password_reset` 类型。现有数据库须先备份、验证恢复能力，再按顺序审查迁移；**禁止重新执行全新安装器或覆盖现有配置**。
- `nav_issue_password_reset_token()` 使用 32 字节密码学随机值、64 位十六进制表示；数据库只存以服务器私钥生成的 HMAC-SHA256 摘要，令牌有效期 **15 分钟**。签发新令牌将撤销同账号尚未用完的旧令牌。
- `nav_redeem_password_reset_token()` 仅接受 64 位十六进制令牌及 14～1024 字节的新密码。在数据库锁和事务中核验有效期与未使用状态，更换 Argon2id 密码，撤销全部账号会话并废止全部令牌，写入最少字段的安全审计事件。成功后令牌不能重放；错误或过期令牌不能修改密码。
- 主动修改密码或由服务器终端恢复密码时，同样废止尚未使用的全部重置令牌。CLI 清理脚本以最多 500 条/批清理过旧失效记录，不影响有效的 15 分钟令牌。
- **刻意没有**新增 `/auth/password-reset/request`、`/auth/password-reset/confirm` 公网路由；CI 验证其返回 404。此阶段没有发邮件、没有收集 SMTP 凭据、没有更改站长密码。不可通过直接调用内部函数来对外提供找回服务。
- 公开找回入口上线前必须先选定可验证的发信方式，增加邮箱所有权校验、请求频率限制、账号枚举防护、邮箱发信失败的一致性处理、防泄露重置链接、真实浏览器端端到端测试与安全审查。**本轮 GitHub CI 仅验证令牌内部逻辑，不代表邮件找回已经可用。**

## v1.9 SMTP 安全发信组件（仅 Draft；默认关闭，未接入公网重置入口）

- 新增 `server/src/mail.php`：调用 PHP cURL/libcurl 通过 **SMTPS 465** 或 **强制 STARTTLS 587** 发送纯文本 UTF-8 邮件；校验服务端 TLS 证书与主机名，不允许明文回退、不接受重定向、禁止 cURL verbose、连接最长等待 5 秒/总耗时最长 12 秒。无需额外 Composer 依赖，但运行机器必须安装 PHP `curl` 扩展。
- 密码、SMTP 授权码和连接设置仅存放在 Web 根目录以外的 `server/config.local.php`；公开示例 `server/config.local.example.php` 提供占位字段，不放真实账号、授权码到 GitHub、CLI 参数或日志。
- **`password_reset_enabled` 默认是 `false`**。仅当明确设置为布尔 `true` 且 SMTP 配置、cURL 扩展、端口/加密组合全部有效时，邮件发送函数才可执行。注意开启这个标志**不会自动创建网页重置接口**：`/auth/password-reset/request` 和 `/auth/password-reset/confirm` 在当前版本仍为 404。
- 重置邮件链接形式为 `https://nav.wwinzs.cn/#password-reset=<64位随机令牌>`。片段不会随页面 HTTP 请求发送给 Nginx，但仍会留在浏览器环境中；后续前端实现必须在读取后立即使用 `history.replaceState` 擦除片段，并设置 `Referrer-Policy: no-referrer`，严禁引入第三方分析或将重置令牌写进日志。
- GitHub CI 使用 localhost 上一次性生成的 TLS 测试证书和模拟 SMTP 收件箱，实际验证认证、邮件上传、编码后的中文正文、正确收件地址与链接格式；也检查对不安全配置的拒绝。**这不是生产邮件提供商的可达性/投递率验收**，尚需另行配置合法发件地址、DNS SPF/DKIM/DMARC，并验证真实投递。
- 启用公网找回前仍需：先验证站长邮箱所有权；加入对账号枚举、请求频率限制和邮件失败的处理；实现可访问的找回页面、令牌去痕与前后端联调；完成真实用户端接收和安全审核。不要仅为了测试而打开正式站找回开关。

## v1.9 网页找回密码（Draft；双重关闭默认，不可直接上生产）

- 后端新增 `POST /auth/password-reset/request`、`POST /auth/password-reset/confirm`，**仅在 `config.local.php` 中明确开启 `password_reset_enabled=true` 且 SMTP 配置经过检查时响应**；否则均返回 HTTP 404。前端除了账户入口开关，还要单独传入 `passwordRecoveryEnabled:true` 才显示“忘记密码”。已部署正式站尚未开启两者。
- 请求端点必须精确匹配 `Origin`，接受 JSON 邮箱，按 IP 最多 8 次/15 分钟、按规范化邮箱最多 3 次/15 分钟限流。**已注册/不存在邮箱、SMTP 接收/失败均返回相同 HTTP 202 正文**，不暴露是否存在站长账号。注意：目前采用同步 SMTP，尽管 HTTP 状态与响应正文一致，**响应耗时仍可能透露账户存在与否；正式上线前还必须解决时间侧信道（例如可靠异步队列），并评估大量申请造成的邮件轰炸**。
- 只有站长账号能签发令牌，数据库保存 HMAC-SHA256 摘要、15 分钟有效。发送失败将废止该次令牌；新申请会使旧令牌失效。SMTP 返回成功仅代表被接收服务器接纳，**不保证投递进入真实邮箱**。
- 确认端点对原点执行严格校验，按 IP 最多 15 次/15 分钟限流；校验令牌格式、新密码 14–1024 字节。数据库事务内一次性兑换、更新密码、使所有旧会话与旧重置令牌失效，并写入审计事件。
- 站点在 `<head>` 中的内联脚本会在加载其他资源前提取 `#password-reset=...` 片段并立即移除 URL hash。后续 JS 将令牌只保留于内存，取消或成功后清除；不存入 localStorage、JSON 收藏和日志。静态主页新增找回邮件表单与重置确认表单，旧导航数据保持不变。**部署前需确认生产 CSP 允许经过审核的提前执行脚本，且不能出现第三方分析或令牌泄露的错误页面**。
- GitHub 浏览器模拟测试覆盖入口单独关闭/开启、表单、片段清除、密码一致性及数据不变；后端在隔离 MySQL 和模拟 SMTPS 上检查通用响应、限流、错误 Origin、发信失败的令牌作废。令牌使用/重放行为另由内部真实 MySQL 单元集成测试覆盖。生产邮箱验证、时间侧信道、真实浏览器/Cookie 和恢复演练仍是**明确未完成的上线门禁**。

## v1.9.1–v1.9.3 三阶段：邮件找回异步队列（仅 Draft）

**v1.9.1 — 加密异步入队。** 新增 `003_async_reset_mail.sql` 第 7 张表 `nav_reset_mail_queue`。网页 `POST /auth/password-reset/request` 现在只验证请求、应用频率限制，使用服务端私钥经 HKDF 派生的独立 AES-256-GCM 密钥加密规范化邮箱，向 MySQL 入队并返回统一 HTTP 202。成功与不存在的邮箱走相同入队流程；**网页请求不查用户表、不产生重置令牌、不执行 SMTP**。数据库只存加密邮箱、随机 nonce、认证标签、邮箱 HMAC 摘要及必要的重试元信息。

**v1.9.2 — 独立 CLI Worker。** `php server/scripts/process-reset-mail.php` 只在 CLI 运行，每次最多处理 10 件任务。MySQL 8 `FOR UPDATE SKIP LOCKED` 锁定待处理行并设置 120 秒租约，worker 解密并验证邮箱后才核对活跃站长账号，为真实站长生成一次性重置令牌并经强制 TLS 的 SMTP 发送；对不存在的账号直接删除任务、不产生邮件。SMTP 失败会使该次令牌失效，最多重试三次，采用延迟 2 分钟、10 分钟的退避；尝试用尽即删除任务。API 限制每 IP 8 次/15 分钟、每邮箱 3 次/15 分钟、全局 200 次/15 分钟；CLI 清理脚本每次删除不超过 500 条超过一天的陈旧队列任务。

**v1.9.3 — 端到端联测和上线门禁。** GitHub CI 在隔离 MySQL 与模拟的受信任 TLS SMTP 环境中，验证已注册和未知邮箱得到相同 202 响应、HTTP 响应期间没有投递、队列消费仅向真实站长发信、邮件令牌一次性兑换、旧密码失效、新密码可登录、非法 Origin 拒绝、无效证书不发送且令牌作废、重试次数封顶。网页本身仍为独立双开关：账号登录与邮箱找回必须分别开启；本地收藏数据不会被修改。

**生产安全门禁：** 目前**没有**在 VPS 安排 worker 的 systemd 定时器，也**没有**真实 SMTP 授权码和发件域名验收。必须先验证备份恢复、PHP cURL/OpenSSL 扩展、worker 用专用低权限系统账户访问私有 `config.local.php`，随后按 `001 → 002 → 003` 顺序执行增量迁移并验证七表状态，才可以部署新后端，最后按计划配置每分钟执行一次的定时任务并做真实邮箱收发测试。定时器应避免并发运行、提供脱敏告警和队列积压指标。绝对不能对已有数据库重跑全新安装器。

**故障边界：** 公开提交返回 202 表示“请求被加入后台队列”，不代表邮件已送达。无 worker 时请求不会发邮件；配置或密钥变更会让已排队邮件无法被解密并丢弃；操作前应清空待处理任务并备份。即使申请接口账号枚举的**同步 SMTP 时间差**已消除，还需真实负载下的响应时间分布检验、邮件滥用评估、真实域名安全验收及停机/重试演练。

## v1.9.4–v1.9.6 连续安全迭代（仅 GitHub Draft）

- **v1.9.4 队列容量与竞态防护：** HTTP 申请在相同匿名流程下检查队列上限 50 条，超过容量不继续持久化请求，但仍保持原有防枚举响应。Worker 对任务完成和重试的数据库更新必须同时匹配 `id` 与递增后的 `attempts`，避免旧任务持有者错误完成被新 Worker 认领的任务。
- **v1.9.5 超时保护：** 队列申请超过 15 分钟不再被领取，Worker 会清理过期请求与超过重试次数的残留任务，避免积压后仍发送可用找回链接。该策略优先保障安全，可能牺牲积压时的邮件送达。
- **v1.9.6 回归验证：** GitHub MySQL 工作流人工种入超过 15 分钟的队列记录，确认 Worker 清理而不发送；保留原有真实 TLS SMTP 邮箱、HTTP 找回、密码变更及浏览器自动化检查。
- **注意：** 队列容量检查和新任务插入目前不是同一事务中的强约束；在并发突发情况下仍可能短暂超过上限。因此此机制仅是负载缓解，不可宣称严格并发容量保证。真实 VPS 尚未启动 Worker/定时器，邮箱收发和生产高并发需要上线前另行测试。
