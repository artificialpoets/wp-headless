import { useEffect, useState, type ComponentType } from 'react'
import { Routes, Route } from 'react-router-dom'
import type { WPHeadlessRuntime, WPHeadlessRequest } from './types/wp-headless'
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

interface AppProps {
  runtime: WPHeadlessRuntime | null
}

interface ActivePost {
  id: number
  post_type: string
  author?: number
}

interface TemplateResolverProps extends AppProps {
  setActivePost: (post: ActivePost | null) => void
}

/**
 * Pick a singular template — registry override, then post_type, then default.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function resolveSingular(request: WPHeadlessRequest, defaults: { Single: ComponentType<any>; Page: ComponentType<any> }): ComponentType<any> {
  const postType = request.queried_object?.post_type
  const slug = request.queried_object?.slug

  if (postType === 'post') return defaults.Single
  if (postType === 'page' && slug && pageTemplates[slug]) return pageTemplates[slug]
  if (postType && singleTemplates[postType]) return singleTemplates[postType]
  return defaults.Page
}

function TemplateResolver({ runtime, setActivePost }: TemplateResolverProps) {
  const request = useResolve(runtime)

  useEffect(() => {
    if (request?.is_singular && request.queried_object?.id) {
      setActivePost({
        id: request.queried_object.id,
        post_type: request.queried_object.post_type || 'post',
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

function EmbedShell({ runtime }: { runtime: WPHeadlessRuntime | null }) {
  const request = useResolve(runtime)
  if (!request || request.kind !== 'embed') return null
  return <Embed runtime={runtime} request={request} />
}

export default function App({ runtime }: AppProps) {
  const site = runtime?.site
  const [activePost, setActivePost] = useState<ActivePost | null>(null)
  const customCss = runtime?.customCss ?? ''
  const initialKind = runtime?.request?.kind
  const isEmbedMode = initialKind === 'embed'

  useEffect(() => {
    if (site?.textDirection) document.documentElement.setAttribute('dir', site.textDirection)
    if (site?.language) document.documentElement.setAttribute('lang', site.language)

    const faviconUrl = site?.favicon?.url
    if (faviconUrl) {
      let link: HTMLLinkElement | null = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]')
      if (!link) {
        link = document.createElement('link')
        link.setAttribute('rel', 'icon')
        document.head.appendChild(link)
      }
      link.setAttribute('href', typeof faviconUrl === 'string' ? faviconUrl : String(faviconUrl))
    }

    if (runtime?.user && !isEmbedMode) {
      const style = document.createElement('style')
      style.setAttribute('data-managed', 'wp-headless-admin')
      style.textContent = '#wpadminbar{display:none!important}html{margin-top:0!important}'
      document.head.appendChild(style)
      return () => { style.remove() }
    }
  }, [site?.textDirection, site?.language, site?.favicon?.url, runtime?.user, isEmbedMode])

  useEffect(() => {
    if (!customCss) return
    const style = document.createElement('style')
    style.setAttribute('data-managed', 'wp-headless-custom-css')
    style.textContent = customCss
    document.head.appendChild(style)
    return () => { style.remove() }
  }, [customCss])

  // Customizer background.
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
