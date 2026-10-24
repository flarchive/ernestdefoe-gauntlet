import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import { t } from '../../common/api';
import type { Person, Status } from '../../common/api';

export function formatDate(iso: string | null): string {
  if (!iso) return '';

  return new Date(iso).toLocaleString(app.translator.getLocale() || undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function pill(status: Status | string): Mithril.Children {
  return <span className={'Gauntlet-pill is-' + status}>{t('status.' + status)}</span>;
}

/** An avatar from a plain person record (not a store model), or their initial. */
export function personAvatar(person: Person): Mithril.Children {
  if (person.avatarUrl) return <img className="Avatar" src={person.avatarUrl} alt="" loading="lazy" decoding="async" />;

  const name = person.displayName || '?';

  return (
    <span className="Avatar" style={{ '--avatar-bg': hue(name), color: '#fff' }}>
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

/** A stable colour per name, for marks with no picture. */
export function hue(name: string): string {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;

  return `hsl(${h} 45% 45%)`;
}
