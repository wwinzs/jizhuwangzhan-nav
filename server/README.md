# 记住网站 v1.9 — 私有账号服务端（第一阶段 / Draft）

**状态：未部署，不代表账号功能已经上线。** 当前仅实现私有账号的基础服务端，不包含公开注册、邀请注册、邮件验证、找回密码、设备管理 UI 或云端数据同步。

## 安全原则
- 默认没有公开注册接口。站长账号只能通过服务器 CLI 创建，且同一安装只创建一位 owner。
- 密码使用 PHP Argon2id 散列，登录返回通用失败信息；按 IP 与邮箱做 15 分钟窗口的登录限流。
- 会话使用 256 位随机令牌，数据库只存 SHA-256 散列；Cookie 采用 HttpOnly、Secure、SameSite=Strict、Host-only (__Host-)。
- 登录和退出要求 **精确匹配 frontend_origin 的 Origin**，拒绝跨站表单提交和其他来源。配置文件存于 public/ 外。
- 必须部署在 HTTPS 环境并使用同一站点下的不同子域名，例如 nav.example.com 和 api.example.com；目前 GitHub Pages 的 github.io 地址和自建 API 域名不保证能正常发送 Strict Cookie。
- 后台不能以项目源码目录作为文档根目录，必须将站点根目录指向 server/public/；禁止直接暴露 config.local.php 或 SQL 文件。

## 服务器准备（待正式部署前执行）
1. 安装 PHP 8.2+（pdo_mysql、openssl、mbstring）、MySQL 8.0+，创建独立数据库和最低权限的应用账号。
2. 将 server/ 文件夹上传到站点工作目录之外，网站根目录**仅**指向 server/public/。PHP-FPM 必须能读取 server/config.local.php。
3. 复制 server/config.local.example.php 为 server/config.local.php；填入真实数据库配置、前端 HTTPS Origin，并运行 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 生成 app_key。
4. 创建数据表：`mysql -u nav_only -p jizhu_nav < server/schema.sql`（部署时应用数据库账号需被授予建表权限，之后可收紧）。
5. 在服务器交互终端执行 `php server/scripts/create-owner.php owner@example.com`，输入两次至少 14 字节的强密码（终端不回显）。
6. 后续用HTTPS测试 `GET /health`、`POST /auth/login`、`GET /auth/me` 和 `POST /auth/logout`。登入 JSON 示例：
   `{"email":"owner@example.com","password":"你的密码"}`。
   POST 必须带准确的 `Origin: https://nav.example.com` 和 `Content-Type: application/json`。

## 当前接口
| 路径 | 方法 | 作用 |
| --- | --- | --- |
| /health | GET | 检测数据库连通性（无秘密信息） |
| /auth/login | POST | 身份校验、限流、签发安全会话 |
| /auth/me | GET | 读取当前登录用户的非敏感字段 |
| /auth/logout | POST | 吊销会话并清除 Cookie |

## 完成 v1.9 前仍需实现
- HTTPS 域名与 PHP-FPM / Nginx 反代的真实环境验收。
- 邮箱验证、找回密码及会话管理；配置 SMTP 和频率限制、一次性验证码。
- 前端可选登录页面、状态提示和退出按钮；未登录继续本地使用。
- 账号隔离、历史审计、会话清理和真实服务端数据接口；这部分属于后续云同步的关键前置条件。
- 多设备数据同步、合并冲突与历史版本将在 v2.0 独立实现。

**切勿把此 Draft 直接部署到生产并宣称完整账号体系。** 配置文件、密码、生产数据库不进入 Git 仓库。
