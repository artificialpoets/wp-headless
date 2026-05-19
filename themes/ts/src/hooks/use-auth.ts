import { useMemo } from 'react'
import type { WPHeadlessRuntime, WPHeadlessUser, WPHeadlessUserCapabilities } from '../types/wp-headless'

export interface AuthApi {
  user: WPHeadlessUser | null
  isLoggedIn: boolean
  can: (capability: keyof WPHeadlessUserCapabilities) => boolean
  canEdit: (post: { author?: number; author_info?: { id?: number } | null } | null | undefined) => boolean
  urls: {
    login?: string
    logout?: string
    register?: string
    lostPassword?: string
    admin?: string
    profile?: string
    registrationEnabled: boolean
  }
}

/**
 * Authentication / current-user state for the React app.
 */
export function useAuth(runtime: WPHeadlessRuntime | null): AuthApi {
  return useMemo<AuthApi>(() => {
    const user = runtime?.user ?? null
    const caps = (user?.capabilities ?? {}) as Partial<WPHeadlessUserCapabilities>
    const urls = runtime?.urls

    function can(capability: keyof WPHeadlessUserCapabilities): boolean {
      return !!caps[capability]
    }

    function canEdit(post: { author?: number; author_info?: { id?: number } | null } | null | undefined): boolean {
      if (!user || !post) return false
      const isOwn = post.author === user.id || post.author_info?.id === user.id
      if (isOwn && can('edit_posts')) return true
      return can('edit_others_posts')
    }

    return {
      user,
      isLoggedIn: !!user,
      can,
      canEdit,
      urls: {
        login: urls?.login,
        logout: urls?.logout,
        register: urls?.register,
        lostPassword: urls?.lost_password,
        admin: urls?.admin,
        profile: urls?.profile,
        registrationEnabled: !!urls?.registration_enabled,
      },
    }
  }, [runtime?.user, runtime?.urls])
}
