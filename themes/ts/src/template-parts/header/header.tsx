import { useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import type { WPHeadlessRuntime } from '../../types/wp-headless'
import type { WPMenuItem } from '../../types/api'
import { useMenu } from '../../hooks/use-menu'
import { usePages } from '../../hooks/use-pages'
import styles from './header.module.css'

interface HeaderProps {
  runtime: WPHeadlessRuntime | null
}

interface NavItemProps {
  item: WPMenuItem
  siteUrl: string
  onNavigate: () => void
}

const HOME_ITEM: WPMenuItem = {
  id: 0, title: 'Home', url: '/', target: '', classes: [], description: '', type: '', object: '', object_id: 0, children: [],
}

/**
 * Site header — always shows logo, site name, and tagline.
 * Uses the "primary" WP menu when one is assigned; falls back to published pages.
 */
export function Header({ runtime }: HeaderProps) {
  const [menuOpen, setMenuOpen] = useState(false)
  const { menu } = useMenu(runtime, { location: 'primary' })
  const { pages } = usePages(runtime)
  const site = runtime?.site
  const siteUrl = site?.url ?? '/'

  const navItems: WPMenuItem[] = menu?.items && menu.items.length > 0
    ? menu.items
    : [HOME_ITEM, ...pages.map((p) => ({
        id: p.id,
        title: p.title.rendered.replace(/<[^>]+>/g, ''),
        url: p.link,
        target: '',
        classes: [],
        description: '',
        type: 'page',
        object: 'page',
        object_id: p.id,
        children: [],
      }))]

  return (
    <header className={styles.header}>
      <div className={styles.inner}>

        <Link
          to="/"
          className={styles.branding}
          aria-label={site?.name ? `${site.name} — home` : 'Home'}
        >
          {site?.logo?.url && (
            <img src={site.logo.url as string} alt="" className={styles.logo} />
          )}
          <div className={styles.brandingText}>
            {site?.name && <span className={styles.siteTitle}>{site.name}</span>}
            {site?.description && (
              <span className={styles.tagline}>{site.description}</span>
            )}
          </div>
        </Link>

        <button
          className={styles.menuToggle}
          aria-expanded={menuOpen}
          aria-controls="primary-nav"
          aria-label={menuOpen ? 'Close menu' : 'Open menu'}
          onClick={() => setMenuOpen((o) => !o)}
        >
          <span aria-hidden="true">{menuOpen ? '✕' : '☰'}</span>
        </button>

        <nav
          id="primary-nav"
          className={`${styles.nav} ${menuOpen ? styles.navOpen : ''}`}
          aria-label="Primary navigation"
        >
          <ul className={styles.list}>
            {navItems.map((item) => (
              <NavItem
                key={item.id}
                item={item}
                siteUrl={siteUrl}
                onNavigate={() => setMenuOpen(false)}
              />
            ))}
          </ul>
        </nav>

      </div>
    </header>
  )
}

/**
 * A single nav item — supports one level of children (dropdown).
 */
function NavItem({ item, siteUrl, onNavigate }: NavItemProps) {
  const path = toRelativePath(item.url, siteUrl)
  const hasChildren = item.children && item.children.length > 0

  return (
    <li className={`${styles.item} ${hasChildren ? styles.hasChildren : ''}`}>
      <NavLink
        to={path}
        className={({ isActive }) => `${styles.link} ${isActive ? styles.active : ''}`}
        target={item.target || undefined}
        onClick={onNavigate}
      >
        {item.title}
      </NavLink>
      {hasChildren && (
        <ul className={styles.subList}>
          {item.children.map((child) => (
            <li key={child.id} className={styles.subItem}>
              <NavLink
                to={toRelativePath(child.url, siteUrl)}
                className={({ isActive }) => `${styles.link} ${isActive ? styles.active : ''}`}
                onClick={onNavigate}
              >
                {child.title}
              </NavLink>
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

/** Convert an absolute WP URL to a root-relative path for React Router. */
function toRelativePath(url: string, siteUrl: string): string {
  if (!url || !siteUrl) return '/'
  try {
    const parsed = new URL(url)
    const base = new URL(siteUrl)
    if (parsed.hostname === base.hostname) return parsed.pathname + parsed.search
  } catch {
    // fallback
  }
  return url
}
