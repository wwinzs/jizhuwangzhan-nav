# 记住网站 · iTab 风格个人导航 v1.1

独立原创的全屏个人导航主页，参考 iTab 的桌面理念，不包含 iTab 源码或品牌资产。

**当前版本是免构建、无后端的静态网站**，不需要 Node.js、PHP、MySQL 或 npm；与此前提供的 Vue 源码包是两种不同的实现，后续可逐步升级。

## 已实现功能

- 摄影壁纸与渐变回退背景、自定义本地壁纸、深色与浅色主题
- 实时时钟、日期问候语、月历、随手记
- 百度、必应、Google 搜索，支持 Ctrl/⌘ + K 快捷键
- 分类导航、添加/编辑/删除网站、常用收藏、名称筛选与拖动排序
- 分类新建、重命名、删除时保留网站
- 本地自动存储、导入/导出 JSON、恢复默认
- 电脑、平板、手机自适应
- GitHub Actions 代码检查和自动部署工作流

## 运行方式

无需安装依赖，下载仓库后直接打开 index.html 即可预览；在生产环境建议使用 HTTPS 静态站点或 GitHub Pages。

## GitHub Pages 上线

1. 进入仓库 Settings → Pages。
2. Build and deployment → Source 选择 **GitHub Actions**。
3. 合并本项目的功能分支至 main；随后在 Actions 查看 “Deploy navigation to GitHub Pages”。
4. 当部署运行成功后，预计访问地址是 https://wwinzs.github.io/jizhuwangzhan-nav/ 。以 GitHub Actions 发布的真实页面地址为准。

如尚未启用 Pages，需要先完成第 1–2 步才能正常部署。站点不会自动成为浏览器“新标签页”，要替换新标签页需要另行开发浏览器扩展。

## 设计与数据说明

数据仅保存在当前浏览器的 localStorage，不会在多台电脑或浏览器之间同步。**清除网站数据、换浏览器或换设备前，请导出 JSON 备份。**

用户自定义壁纸支持 PNG / JPEG / WebP，限 2MB。导入备份有数量、长度、网址协议和文件大小检查。所有网址只允许 HTTP/HTTPS，禁止脚本或文件协议。此项目不使用第三方跟踪脚本。

本项目为首版，建议在后续版本增加：移动端拖动排序、快捷网站图标自动获取、多设备同步、PWA / 浏览器扩展、自动化端到端交互测试。GitHub Actions 的语法检查**不是**对所有交互与浏览器兼容性的完整验收。

## 代码结构

- index.html：页面语义结构与弹窗
- style.css：原有交互布局基础样式
- desktop.css：iTab 风格的壁纸优先桌面布局、紧凑组件、无底板网站图标
- app.js：页面状态、收藏管理、持久化与数据校验
- favicon.svg：网站图标
- .github/workflows/check.yml：自动语法检查
- .github/workflows/deploy.yml：GitHub Pages 发布


## v1.1 视觉改版
- 桌面版左侧改为 76px 图标分组 Dock；保留移动端抽屉菜单。
- 时间与搜索框缩小居中，顶栏收敛到右上角。
- 小组件压缩为统一桌面高度；网站不再使用大面积玻璃卡片，图标可快速辨认。
- 网站图标尝试通过 Google favicon 服务获取；不可用时回退到原来的字符图标。这个第三方图标服务会收到网站域名请求。
- 默认壁纸使用 Unsplash 远程摄影照片；联网时加载，加载失败时显示 CSS 渐变底色。上传本地壁纸仍然有效。
- 此次改造不清除旧版本 localStorage 数据；仅为外观升级，不包含拖动自由布局、云同步或浏览器新标签页接管。
