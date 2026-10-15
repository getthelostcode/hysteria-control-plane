# 品牌主题与配色

面板主题是一份独立的 Vite 产物：`resources/css/filament/admin/theme.css`，
在 `AdminPanelProvider` 中通过 `->viteTheme(...)` 注册（与 `app.css` 完全解耦）。

## 1. 三层配色机制

1. **编译期骨架**：`theme.css` 里 `@import` Filament 主题 + `@config tailwind.config.js`
   （content 覆盖 `app/Filament/**/*.php` 与本项目的 `resources/views/filament/**`，
   否则自定义 blade 里的 Tailwind 类不会生成）。
2. **数据库驱动（默认值）**：`App\Support\Setting::cssVariables()` 把 `settings` 表里的
   `theme_preset`（ocean/forest/sunset）或自定义 `theme_primary` 展开成
   `--primary-50 … --primary-950`，由 `PanelsRenderHook::HEAD_END` 注入到 `<head>`。
   Filament 的 Tailwind preset 把每个 `primary-*` 工具类解析为
   `rgba(var(--primary-500), <alpha-value>)`，所以改这些变量就是换主题。
3. **运行期即时切换**：顶栏的 `preset-switcher` 组件把三套调色板内联成 JS 对象，
   点击时直接 `document.documentElement.style.setProperty(...)` 并写入 `localStorage`，
   无需刷新、无需重新部署。`theme.css` 里也预置了
   `html[data-cp-preset="ocean"] { --primary-500: 59, 130, 246; ... }` 作为静态兜底。

自定义主色（“面板设置 → 主题 → 自定义主色”）走 `App\Support\ColorPalette::fromHex()`：
按 HSL 生成 50→950 的完整色阶，因此任何单色都能得到一套可用的语义色，而不是只有一档色。

## 2. 亮/暗模式

* 面板启用 `->darkMode(true)`（Filament 内置，默认跟随系统）。
* `settings.theme_mode`（system/light/dark）作为**默认值**注入：没有本地偏好时写入
  `localStorage.theme` 并同步 `<html class="dark">`；用户在顶栏切换后本地偏好优先。
* 主题里所有自定义样式都同时覆盖亮暗（见 `.cp-diff`、`.fi-simple-layout` 的 `.dark` 变体）。

## 3. 自定义内容

| 位置 | 文件 |
|---|---|
| Logo / 品牌名 | `settings.brand_name`、`settings.brand_logo`（URL），在 Panel 里以闭包读取 |
| 页脚 | `resources/views/filament/admin/footer.blade.php` + `settings.panel_footer` |
| 登录页背景 | `.fi-simple-layout` 的径向渐变（使用 `--primary-*`） |
| 代码/YAML 字体 | `.cp-mono` + `--cp-code-font` |
| Diff 高亮 | `.cp-diff .diff-added / .diff-removed / .diff-same` |
| 预设切换点 | `.cp-preset-ocean / -forest / -sunset` |

## 4. 开发与构建

```bash
npm install --include=dev    # 该环境的 npm 默认会跳过 devDependencies，必须显式带上
npm run dev                  # vite dev server
npm run build                # 产出 public/build/manifest.json + theme-*.css
```

主题产物与 `app.css` 是两个不同的文件（`build/assets/theme-*.css` / `app-*.css`），
互相不包含对方的类：改了 `theme.css` 一定要 `npm run build`，否则面板继续用旧产物。
