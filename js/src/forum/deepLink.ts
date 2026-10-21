/**
 * `?photo=12` from an alert, an email or a reply. Read when the discussion
 * page starts: Flarum then rewrites /d/3 to /d/3-its-slug and the query goes.
 */
let pending: number | null = null;

export function remember() {
  pending = Number(new URLSearchParams(location.search).get('photo')) || null;
}

export function take(): number | null {
  const id = pending;
  pending = null;
  return id;
}
