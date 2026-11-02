import type { Match, Tournament } from '../../common/api';

/** Box and spacing sizes, in SVG pixels. The SVG is never scaled down: it scrolls. */
export const BOX_W = 196;
export const ROW_H = 26;
export const BOX_H = ROW_H * 2;
export const COL_GAP = 40;
export const ROW_GAP = 18;
export const HEAD_H = 28;

export interface Placed {
  match: Match;
  x: number;
  /** Top of the box. */
  y: number;
  column: number;
}

export interface Section {
  key: 'W' | 'L' | 'F';
  title: string | null;
  columns: { title: string; x: number }[];
  placed: Placed[];
  width: number;
  height: number;
}

/** Which match feeds each slot, from the links the server sends with every match. */
export function feeders(t: Tournament): Map<string, Map<number, { from: Match; route: 'next' | 'loser' }>> {
  const into = new Map<string, Map<number, { from: Match; route: 'next' | 'loser' }>>();

  for (const m of t.matches) {
    for (const route of ['next', 'loser'] as const) {
      const link = m[route];
      if (!link) continue;
      if (!into.has(link[0])) into.set(link[0], new Map());
      into.get(link[0])!.set(link[1], { from: m, route });
    }
  }

  return into;
}

export function maxRound(t: Tournament, bracket: string): number {
  return t.matches.reduce((max, m) => (m.bracket === bracket ? Math.max(max, m.round) : max), 0);
}

/**
 * Lay a bracket out as columns of boxes. Leaves (matches nothing in the same
 * section feeds) stack top to bottom; every other match sits level with the
 * middle of the matches that feed it — which also centres a losers-bracket
 * drop-in round on the round before it. Void matches (no one will play) are
 * left out; byes keep their place, so the tree stays a tree.
 */
export function layout(
  t: Tournament,
  titleFor: (bracket: string, round: number, rounds: number) => string,
  sectionTitle: (key: 'W' | 'L' | 'F') => string | null
): Section[] {
  const into = feeders(t);
  const sections: Section[] = [];
  const keys: ('W' | 'L' | 'F')[] = t.format === 'double' ? ['W', 'L', 'F'] : ['W'];

  for (const key of keys) {
    const brackets = key === 'W' && t.format === 'single' ? ['W', 'P'] : [key];
    const matches = t.matches.filter((m) => brackets.includes(m.bracket));
    if (!matches.length) continue;

    const rounds = maxRound(t, key);
    const centre = new Map<string, number>();
    let leaf = 0;
    const placed: Placed[] = [];

    const sorted = [...matches].sort(
      (a, b) => a.round - b.round || (a.bracket === 'P' ? 1 : 0) - (b.bracket === 'P' ? 1 : 0) || a.position - b.position
    );
    for (const m of sorted) {
      if (m.status === 'void' && !(m.bracket === 'F' && m.round === 2)) continue;

      const column = m.bracket === 'F' ? m.round - 1 : m.round - 1;
      let y: number;

      const fed = [...(into.get(m.key)?.values() ?? [])].filter(
        (f) => f.route === 'next' && brackets.includes(f.from.bracket) && centre.has(f.from.key)
      );
      if (m.bracket === 'P') {
        // Third place sits under the final.
        const final = centre.get(`W:${rounds}:1`) ?? 0;
        y = final + BOX_H + ROW_GAP * 2;
      } else if (m.bracket === 'F' && m.round === 2) {
        y = centre.get('F:1:1') ?? 0;
      } else if (fed.length) {
        y = fed.reduce((sum, f) => sum + centre.get(f.from.key)!, 0) / fed.length;
      } else {
        y = leaf * (BOX_H + ROW_GAP) + BOX_H / 2;
        leaf++;
      }

      centre.set(m.key, y);
      placed.push({ match: m, x: column * (BOX_W + COL_GAP), y: y - BOX_H / 2 + HEAD_H, column });
    }

    if (!placed.length) continue;

    const columns: { title: string; x: number }[] = [];
    const columnCount = Math.max(...placed.map((p) => p.column)) + 1;
    for (let c = 0; c < columnCount; c++) {
      columns.push({ title: key === 'F' ? titleFor('F', c + 1, rounds) : titleFor(key, c + 1, rounds), x: c * (BOX_W + COL_GAP) });
    }

    const top = Math.min(...placed.map((p) => p.y));
    if (top < HEAD_H) placed.forEach((p) => (p.y += HEAD_H - top));

    sections.push({
      key,
      title: sectionTitle(key),
      columns,
      placed,
      width: columnCount * (BOX_W + COL_GAP) - COL_GAP,
      height: Math.max(...placed.map((p) => p.y)) + BOX_H + 4,
    });
  }

  return sections;
}

/**
 * Who a member could have in a match, by their own picks so far — the same
 * rule the server checks: a slot fed by a match that needs a pick holds that
 * pick (or, for third place, the side they didn't pick); otherwise whoever is
 * actually in it.
 */
export function candidates(t: Tournament, m: Match, picks: Record<string, number>, into = feeders(t)): number[] {
  if (m.status === 'bye' || m.status === 'void') return [];
  const out: number[] = [];

  for (const slot of [1, 2] as const) {
    const feed = into.get(m.key)?.get(slot);
    const actual = slot === 1 ? m.entrant1Id : m.entrant2Id;

    if (!feed || feed.from.status === 'bye' || feed.from.status === 'void') {
      if (actual) out.push(actual);
      continue;
    }

    const picked = picks[feed.from.key];
    if (!picked) continue;

    if (feed.route === 'next') out.push(picked);
    else {
      const other = candidates(t, feed.from, picks, into).filter((id) => id !== picked);
      if (other.length === 1) out.push(other[0]);
    }
  }

  return out;
}

/** Drop picks that no longer fit after an earlier pick changed. */
export function prune(t: Tournament, picks: Record<string, number>): Record<string, number> {
  const into = feeders(t);
  const clean: Record<string, number> = {};
  const order = [...t.matches].sort(
    (a, b) => (a.bracket === 'P' ? 1 : 0) - (b.bracket === 'P' ? 1 : 0) || a.round - b.round || a.position - b.position
  );

  for (const m of order) {
    const pick = picks[m.key];
    if (pick && candidates(t, m, clean, into).includes(pick)) clean[m.key] = pick;
  }

  return clean;
}
