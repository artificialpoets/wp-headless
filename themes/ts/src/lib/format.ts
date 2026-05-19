/**
 * Format a WP date string using the site's language and timezone.
 *
 * @param dateString  ISO 8601 date from the REST API.
 * @param language    BCP 47 locale (e.g. "en-US"). Defaults to browser locale.
 * @param timezone    IANA timezone (e.g. "America/New_York"). Defaults to local.
 * @param options     Override any format options.
 */
export function formatDate(
  dateString: string,
  language?: string,
  timezone?: string,
  options: Intl.DateTimeFormatOptions = {}
): string {
  if (!dateString) return ''
  try {
    const date = new Date(dateString)
    return new Intl.DateTimeFormat(language || undefined, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      timeZone: timezone || undefined,
      ...options,
    }).format(date)
  } catch {
    return new Date(dateString).toLocaleDateString()
  }
}

/**
 * Short date: "Jan 5, 2025"
 */
export function formatDateShort(
  dateString: string,
  language?: string,
  timezone?: string
): string {
  return formatDate(dateString, language, timezone, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

/**
 * Relative time: "3 days ago", "2 months ago"
 * Falls back to short date for older posts.
 */
export function formatDateRelative(dateString: string, language?: string): string {
  if (!dateString) return ''
  try {
    const date = new Date(dateString)
    const diff = Date.now() - date.getTime()
    const days = Math.floor(diff / (1000 * 60 * 60 * 24))

    if (days < 1) return 'Today'
    if (days < 7) {
      const rtf = new Intl.RelativeTimeFormat(language || undefined, { numeric: 'auto' })
      return rtf.format(-days, 'day')
    }
    if (days < 30) {
      const weeks = Math.floor(days / 7)
      const rtf = new Intl.RelativeTimeFormat(language || undefined, { numeric: 'auto' })
      return rtf.format(-weeks, 'week')
    }
    return formatDateShort(dateString, language)
  } catch {
    return formatDateShort(dateString)
  }
}

/**
 * Format a timezone string for display: "America/New_York" → "America / New York"
 */
export function formatTimezone(timezone: string): string {
  if (!timezone) return ''
  return timezone.replace(/_/g, ' ').replace(/\//g, ' / ')
}
