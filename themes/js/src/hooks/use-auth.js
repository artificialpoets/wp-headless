import { useMemo } from 'react'

/**
 * Authentication / current-user state — reads the runtime.user payload that
 * the wp-headless plugin injects when the visitor is logged in.
 *
 * Returns a tiny API the React app can use to:
 *   - check `isLoggedIn` / read `user`
 *   - test capabilities via `can('edit_others_posts')`
 *   - check whether the user can edit a specific post (`canEdit(post)`)
 *   - get the login / logout / register / lost-password URLs
 *
 * This is intentionally sync: the runtime is injected with the page, so the
 * UI can render the admin bar and edit links without a fetch round-trip.
 *
 * @param {object} runtime
 */
export function useAuth(runtime) {
  return useMemo(() => {
    const user = runtime?.user ?? null
    const caps = user?.capabilities ?? {}
    const urls = runtime?.urls ?? {}

    function can(capability) {
      return !!caps[capability]
    }

    function canEdit(post) {
      if (!user || !post) return false
      // Authors can edit their own; editors+ can edit others'.
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
        login: urls.login,
        logout: urls.logout,
        register: urls.register,
        lostPassword: urls.lost_password,
        admin: urls.admin,
        profile: urls.profile,
        registrationEnabled: !!urls.registration_enabled,
      },
    }
  }, [runtime?.user, runtime?.urls])
}
