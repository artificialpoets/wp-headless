/**
 * Convert the merged theme.json data exposed in `runtime.theme.styles` into
 * a CSS `:root { … }` declaration that mirrors WordPress's own CSS variable
 * naming convention. Blocks rendered from `content.rendered` reference these
 * variables via classes like `.has-accent-3-color` / `.has-medium-font-size`,
 * so once we inject them, blocks pick up the theme's palette automatically.
 *
 * Naming follows WP's standard:
 *   --wp--preset--color--{slug}           → palette
 *   --wp--preset--font-size--{slug}       → typography.fontSizes
 *   --wp--preset--font-family--{slug}     → typography.fontFamilies
 *   --wp--preset--spacing--{slug}         → spacing.spacingSizes
 *   --wp--preset--gradient--{slug}        → color.gradients
 *   --wp--style--global--content-size     → layout.contentSize
 *   --wp--style--global--wide-size        → layout.wideSize
 *
 * @param {object} themeStyles `runtime.theme.styles`
 * @returns {string|null} CSS string ready to inject, or null when empty.
 */
export function themeTokensToCss(themeStyles) {
  if (!themeStyles) return null
  const lines = []

  for (const c of themeStyles.color?.palette ?? []) {
    if (c.slug && c.color) lines.push(`--wp--preset--color--${c.slug}: ${c.color};`)
  }
  for (const g of themeStyles.color?.gradients ?? []) {
    if (g.slug && g.gradient) lines.push(`--wp--preset--gradient--${g.slug}: ${g.gradient};`)
  }
  for (const s of themeStyles.typography?.fontSizes ?? []) {
    if (s.slug && s.size) lines.push(`--wp--preset--font-size--${s.slug}: ${s.size};`)
  }
  for (const f of themeStyles.typography?.fontFamilies ?? []) {
    if (f.slug && f.fontFamily) lines.push(`--wp--preset--font-family--${f.slug}: ${f.fontFamily};`)
  }
  for (const s of themeStyles.spacing?.spacingSizes ?? []) {
    if (s.slug && s.size) lines.push(`--wp--preset--spacing--${s.slug}: ${s.size};`)
  }

  const layout = themeStyles.layout ?? {}
  if (layout.contentSize) lines.push(`--wp--style--global--content-size: ${layout.contentSize};`)
  if (layout.wideSize) lines.push(`--wp--style--global--wide-size: ${layout.wideSize};`)

  if (lines.length === 0) return null
  return `:root {\n  ${lines.join('\n  ')}\n}`
}
