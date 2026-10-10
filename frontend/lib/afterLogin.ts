// Where to go after signing in or registering: back to an invitation link that was opened while
// signed out. Only invite links are accepted, so this can never redirect to another site.

const KEY = "respirosync_after_login";
const INVITE_PATH = /^\/invite\?token=[A-Za-z0-9]+$/;

export function rememberAfterLogin(path: string): void {
  try {
    if (INVITE_PATH.test(path)) sessionStorage.setItem(KEY, path);
  } catch {}
}

/** The remembered invite link (used once), else the dashboard. */
export function afterLoginPath(): string {
  try {
    const path = sessionStorage.getItem(KEY);
    sessionStorage.removeItem(KEY);
    if (path && INVITE_PATH.test(path)) return path;
  } catch {}
  return "/";
}
