/**
 * Convert merged theme.json data into a CSS `:root { … }` declaration that
 * mirrors WordPress's CSS variable naming convention.
 *
 * See themes/js/src/lib/theme-tokens.js for the rationale; this is the
 * typed twin.
 */

export interface ThemeStyles {
  color?: {
    palette?: Array<{ slug?: string; name?: string; color?: string }>
    gradients?: Array<{ slug?: string; name?: string; gradient?: string }>
  }
  typography?: {
    fontSizes?: Array<{ slug?: string; name?: string; size?: string }>
    fontFamilies?: Array<{ slug?: string; name?: string; fontFamily?: string }>
  }
  spacing?: {
    spacingSizes?: Array<{ slug?: string; name?: string; size?: string }>
  }
  layout?: {
    contentSize?: string | null
    wideSize?: string | null
  }
}

export function themeTokensToCss(themeStyles: ThemeStyles | undefined | null): string | null {
  if (!themeStyles) return null
  const lines: string[] = []

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

  const layout = themeStyles.layout
  if (layout?.contentSize) lines.push(`--wp--style--global--content-size: ${layout.contentSize};`)
  if (layout?.wideSize) lines.push(`--wp--style--global--wide-size: ${layout.wideSize};`)

  if (lines.length === 0) return null
  return `:root {\n  ${lines.join('\n  ')}\n}`
}
