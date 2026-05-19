import { Link } from 'react-router-dom'
import { useMenu } from '../../hooks/use-menu'
import { formatTimezone } from '../../lib/format'
import styles from './footer.module.css'

export function Footer({ runtime }) {
  const site = runtime?.site ?? {}
  const year = new Date().getFullYear()
  const { menu } = useMenu(runtime, { location: 'footer' })

  return (
    <footer className={styles.footer}>
      <div className={styles.inner}>

        <div className={styles.top}>
          <div className={styles.brand}>
            {site.logo?.url && (
              <img src={site.logo.url} alt={site.name || ''} className={styles.footerLogo} />
            )}
            {site.name && <strong className={styles.name}>{site.name}</strong>}
            {site.description && <p className={styles.description}>{site.description}</p>}
          </div>

          {menu?.items?.length > 0 && (
            <nav className={styles.nav} aria-label="Footer navigation">
              <ul className={styles.navList}>
                {menu.items.map((item) => {
                  const isExternal = item.target === '_blank'
                  const path = (() => { try { return new URL(item.url).pathname } catch { return item.url } })()
                  return (
                    <li key={item.id}>
                      {isExternal ? (
                        <a href={item.url} target="_blank" rel="noreferrer" className={styles.navLink}>
                          {item.title}
                        </a>
                      ) : (
                        <Link to={path} className={styles.navLink}>{item.title}</Link>
                      )}
                    </li>
                  )
                })}
              </ul>
            </nav>
          )}

          <dl className={styles.meta}>
            {site.language && (
              <>
                <dt>Language</dt>
                <dd lang={site.language}>{site.language.replace('_', '-')}</dd>
              </>
            )}
            {site.timezone && (
              <>
                <dt>Timezone</dt>
                <dd>{formatTimezone(site.timezone)}</dd>
              </>
            )}
            {site.charset && (
              <>
                <dt>Encoding</dt>
                <dd>{site.charset.toUpperCase()}</dd>
              </>
            )}
            {site.textDirection === 'rtl' && (
              <>
                <dt>Text dir.</dt>
                <dd>Right-to-left</dd>
              </>
            )}
          </dl>
        </div>

        <div className={styles.bottom}>
          <p className={styles.credit}>
            &copy; {year} {site.name || ''}. Powered by{' '}
            <a href="https://wordpress.org" target="_blank" rel="noreferrer">WordPress</a>
            {' & '}
            <a href="https://github.com/artificialpoets/wp-headless" target="_blank" rel="noreferrer">WP Headless</a>.
          </p>
          {site.url && (
            <a className={styles.siteLink} href={site.url} target="_blank" rel="noreferrer">
              {site.url.replace(/^https?:\/\//, '')}
            </a>
          )}
        </div>

      </div>
    </footer>
  )
}
