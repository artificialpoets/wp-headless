import { useEffect, useState } from 'react'
import { Routes, Route } from 'react-router-dom'
import { useResolve } from './hooks/use-resolve'
import { Header } from './template-parts/header/header'
import { Footer } from './template-parts/footer/footer'
import { AdminBar } from './components/admin-bar'
import { FrontPage } from './templates/front-page'
import { Index } from './templates/index'
import { Single } from './templates/single'
import { Page } from './templates/page'
import { Archive } from './templates/archive'
import { Author } from './templates/author'
import { DateArchive } from './templates/date'
import { Attachment } from './templates/attachment'
import { CPTArchive } from './templates/cpt-archive'
import { Search } from './templates/search'
import { Login } from './templates/login'
import { Register } from './templates/register'
import { LostPassword } from './templates/lost-password'
import { Profile } from './templates/profile'
import { ProfilePasswords } from './templates/profile-passwords'
import { Embed } from './templates/embed'
import { NotFound } from './templates/404'
import { pageTemplates, singleTemplates } from './templates/registry'
import styles from './App.module.css'

/**
 * Pick a template component for a singular request, honoring the slug- and
 * post-type-keyed registries before falling through to the generic Single/Page.
 */
function resolveSingular(request, { Single, Page }) {
  const post_type = request.queried_object?.post_type
  const slug = request.queried_object?.slug

  if (post_type === 'post') {
    return Single
  }
  if (post_type === 'page' && slug && pageTemplates[slug]) {
    return pageTemplates[slug]
  }
  if (post_type && singleTemplates[post_type]) {
    return singleTemplates[post_type]
  }
  return Page
}

function TemplateResolver({ runtime, setActivePost }) {
  const request = useResolve(runtime)

  useEffect(() => {
    if (request?.is_singular && request.queried_object?.id) {
      setActivePost({
        id: request.queried_object.id,
        post_type: request.queried_object.post_type || 'post',
        author: request.queried_object.author || 0,
      })
    } else {
      setActivePost(null)
    }
  }, [request?.queried_object?.id, request?.is_singular, setActivePost])

  if (!request) {
    return <div className={styles.loading} aria-busy="true"><span>Loading…</span></div>
  }

  // Auth pages.
  if (request.kind === 'login')             return <Login runtime={runtime} request={request} />
  if (request.kind === 'register')          return <Register runtime={runtime} request={request} />
  if (request.kind === 'lost_password')     return <LostPassword runtime={runtime} request={request} />
  if (request.kind === 'profile')           return <Profile runtime={runtime} request={request} />
  if (request.kind === 'profile_passwords') return <ProfilePasswords runtime={runtime} request={request} />

  if (request.is_front_page) return <FrontPage runtime={runtime} request={request} />
  if (request.is_attachment) return <Attachment runtime={runtime} request={request} />
  if (request.is_singular) {
    const Component = resolveSingular(request, { Single, Page })
    return <Component runtime={runtime} request={request} />
  }
  if (request.is_author) return <Author runtime={runtime} request={request} />
  if (request.is_date) return <DateArchive runtime={runtime} request={request} />
  if (request.is_post_type_archive) return <CPTArchive runtime={runtime} request={request} />
  if (request.is_archive) return <Archive runtime={runtime} request={request} />
  if (request.is_search) return <Search runtime={runtime} request={request} />
  if (request.is_404) return <NotFound runtime={runtime} />

  return <Index runtime={runtime} request={request} />
}

/**
 * Resolver shim for embed kind — render only the Embed component, no chrome.
 * Returns null when the current request isn't an embed so the regular layout
 * takes over.
 */
function EmbedShell({ runtime }) {
  const request = useResolve(runtime)
  if (!request || request.kind !== 'embed') return null
  return <Embed runtime={runtime} request={request} />
}

export default function App({ runtime }) {
  const site = runtime?.site ?? {}
  const [activePost, setActivePost] = useState(null)
  const customCss = runtime?.customCss ?? ''
  const initialKind = runtime?.request?.kind

  // Detect "embed" mode up-front. The runtime.request is authoritative for the
  // initial render; client-side navigation into / out of embed requires a full
  // page-load anyway (the iframe is unlikely to navigate), so we don't watch.
  const isEmbedMode = initialKind === 'embed'

  useEffect(() => {
    if (site.textDirection) document.documentElement.setAttribute('dir', site.textDirection)
    if (site.language) document.documentElement.setAttribute('lang', site.language)

    if (site.favicon?.url) {
      let link = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]')
      if (!link) {
        link = document.createElement('link')
        link.setAttribute('rel', 'icon')
        document.head.appendChild(link)
      }
      link.setAttribute('href', site.favicon.url)
    }

    // Suppress the WP-injected admin bar styles when our React admin bar is present.
    if (runtime?.user && !isEmbedMode) {
      const style = document.createElement('style')
      style.setAttribute('data-managed', 'wp-headless-admin')
      style.textContent = '#wpadminbar{display:none!important}html{margin-top:0!important}'
      document.head.appendChild(style)
      return () => { style.remove() }
    }
  }, [site.textDirection, site.language, site.favicon?.url, runtime?.user, isEmbedMode])

  // Inject Customizer Additional CSS as the last <style> so it overrides theme.
  useEffect(() => {
    if (!customCss) return
    const style = document.createElement('style')
    style.setAttribute('data-managed', 'wp-headless-custom-css')
    style.textContent = customCss
    document.head.appendChild(style)
    return () => { style.remove() }
  }, [customCss])

  // Apply Customizer background to <body>.
  useEffect(() => {
    const bg = runtime?.site?.background
    if (!bg) return
    const body = document.body
    if (bg.color) body.style.backgroundColor = bg.color
    if (bg.image) {
      body.style.backgroundImage = `url(${bg.image})`
      body.style.backgroundRepeat = bg.repeat || 'repeat'
      body.style.backgroundSize = bg.size || 'auto'
      body.style.backgroundAttachment = bg.attachment || 'scroll'
      body.style.backgroundPosition = bg.position || 'left top'
    }
    return () => {
      body.style.backgroundColor = ''
      body.style.backgroundImage = ''
      body.style.backgroundRepeat = ''
      body.style.backgroundSize = ''
      body.style.backgroundAttachment = ''
      body.style.backgroundPosition = ''
    }
  }, [runtime?.site?.background?.image, runtime?.site?.background?.color])

  // Embed mode renders only the embed card — no header, footer, or admin bar.
  if (isEmbedMode) {
    return (
      <Routes>
        <Route path="*" element={<EmbedShell runtime={runtime} />} />
      </Routes>
    )
  }

  return (
    <div className={styles.app}>
      <a className="wph-skip-link" href="#main-content">Skip to content</a>
      <AdminBar runtime={runtime} editPost={activePost} />
      <Header runtime={runtime} />
      <main className={styles.main} id="main-content" tabIndex={-1}>
        <Routes>
          <Route path="*" element={<TemplateResolver runtime={runtime} setActivePost={setActivePost} />} />
        </Routes>
      </main>
      <Footer runtime={runtime} />
    </div>
  )
}
