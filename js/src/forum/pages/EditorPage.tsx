import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { api, t, tournamentUrl } from '../../common/api';
import type { Tournament } from '../../common/api';
import injectStyles from '../styles';

type Values = Record<string, any>;

const DEFAULTS: Values = {
  title: '',
  game: '',
  description: '',
  format: 'single',
  entryMode: 'members',
  entryType: 'solo',
  teamSize: 2,
  maxEntrants: 16,
  waitlist: true,
  startsAt: '',
  registrationClosesAt: '',
  checkinMinutes: 0,
  seeding: 'random',
  bestOf: 1,
  thirdPlace: false,
  grandFinalReset: true,
  swissRounds: 0,
  allowDraws: false,
  confirmation: 'agree',
  matchDiscussions: false,
  tagId: '',
  predictions: false,
  predictionsLockAt: '',
  predictionPoints: '',
  rewardPoints: ['', '', ''],
  rewardBadgeId: '',
};

/** /tournaments/new and /tournaments/:id/edit — the organiser's form. */
export default class EditorPage extends Page {
  id: number | null = null;
  values: Values = { ...DEFAULTS, rewardPoints: ['', '', ''] };
  status: Tournament['status'] = 'draft';
  loading = false;
  saving = false;
  badges: { id: string; name: string }[] = [];

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    injectStyles();
    this.id = m.route.param('id') ? parseInt(String(m.route.param('id')), 10) : null;
    app.setTitle(extractText(t(this.id ? 'editor.edit_title' : 'editor.new_title')));

    if (this.id) {
      this.loading = true;
      api.show(this.id).then(({ data }) => {
        this.status = data.status;
        this.values = {
          ...DEFAULTS,
          ...Object.fromEntries(Object.keys(DEFAULTS).map((k) => [k, (data as any)[k] ?? DEFAULTS[k]])),
          startsAt: toLocal(data.startsAt),
          registrationClosesAt: toLocal(data.registrationClosesAt),
          predictionsLockAt: toLocal(data.predictionsLockAt),
          predictionPoints: (data.predictionPoints || []).join(', '),
          rewardPoints: [0, 1, 2].map((i) => (data.rewardPoints?.[i] ? String(data.rewardPoints[i]) : '')),
          rewardBadgeId: data.rewardBadgeId ? String(data.rewardBadgeId) : '',
          tagId: data.tagId ? String(data.tagId) : '',
          description: data.description || '',
        };
        this.loading = false;
        m.redraw();
      });
    }

    // FoF Badges may not be installed: a quiet miss, not an error alert.
    if (app.forum.attribute('canRewardTournaments')) {
      app
        .request<{ data: { id: string; attributes: { name: string } }[] }>({
          method: 'GET',
          url: app.forum.attribute('apiUrl') + '/badges',
          errorHandler: () => {},
        })
        .then((res) => {
          this.badges = res.data.map((b) => ({ id: b.id, name: b.attributes.name }));
          m.redraw();
        })
        .catch(() => {});
    }
  }

  started(): boolean {
    return ['running', 'finished', 'cancelled'].includes(this.status);
  }

  view(): Mithril.Children {
    return (
      <PageStructure className="Gauntlet GauntletEditor" sidebar={() => <IndexSidebar />} loading={this.loading}>
        <h2>{t(this.id ? 'editor.edit_title' : 'editor.new_title')}</h2>
        <form className="Gauntlet-form" onsubmit={(e: SubmitEvent) => this.submit(e)}>
          {this.basics()}
          {this.shape()}
          {this.entry()}
          {this.play()}
          {this.values.format === 'single' && this.values.entryMode === 'staff' ? this.predictionFields() : null}
          {app.forum.attribute('canRewardTournaments') && (app.forum.attribute('tournamentPointsAvailable') || this.badges.length)
            ? this.rewards()
            : null}
          <Button type="submit" className="Button Button--primary" loading={this.saving}>
            {t(this.id ? 'editor.save' : 'editor.create')}
          </Button>
        </form>
      </PageStructure>
    );
  }

  input(key: string, type = 'text', attrs: Record<string, unknown> = {}): Mithril.Children {
    return (
      <div className="Form-group">
        <label htmlFor={'gauntlet-' + key}>{t('editor.fields.' + key)}</label>
        <input
          id={'gauntlet-' + key}
          className="FormControl"
          type={type}
          value={this.values[key]}
          oninput={(e: InputEvent) => (this.values[key] = (e.target as HTMLInputElement).value)}
          {...attrs}
        />
      </div>
    );
  }

  select(key: string, options: string[], disabled = false): Mithril.Children {
    return (
      <div className="Form-group">
        <label htmlFor={'gauntlet-' + key}>{t('editor.fields.' + key)}</label>
        <select
          id={'gauntlet-' + key}
          className="FormControl"
          disabled={disabled}
          onchange={(e: Event) => (this.values[key] = (e.target as HTMLSelectElement).value)}
        >
          {options.map((o) => (
            <option value={o} selected={String(this.values[key]) === o}>
              {t(`editor.options.${key}.${o}`)}
            </option>
          ))}
        </select>
      </div>
    );
  }

  toggle(key: string, disabled = false): Mithril.Children {
    return (
      <div className="Form-group">
        <Switch state={!!this.values[key]} disabled={disabled} onchange={(v: boolean) => (this.values[key] = v)}>
          {t('editor.fields.' + key)}
        </Switch>
      </div>
    );
  }

  basics(): Mithril.Children {
    return (
      <fieldset>
        <legend>{t('editor.basics')}</legend>
        {this.input('title', 'text', { maxlength: 150, required: true })}
        {this.input('game', 'text', { maxlength: 80, placeholder: extractText(t('editor.game_placeholder')), disabled: this.started() })}
        <div className="Form-group">
          <label htmlFor="gauntlet-description">{t('editor.fields.description')}</label>
          <textarea
            id="gauntlet-description"
            className="FormControl"
            rows={4}
            maxlength={5000}
            value={this.values.description}
            oninput={(e: InputEvent) => (this.values.description = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
      </fieldset>
    );
  }

  shape(): Mithril.Children {
    const locked = this.started();
    const v = this.values;

    return (
      <fieldset>
        <legend>{t('editor.format')}</legend>
        {locked ? <p className="helpText">{t('editor.locked')}</p> : null}
        <div className="Gauntlet-grid2">
          {this.select('format', ['single', 'double', 'round_robin', 'swiss'], locked)}
          {this.select('entryMode', ['members', 'staff'], locked || (this.id !== null && this.status !== 'draft'))}
        </div>
        {v.format === 'single' ? this.toggle('thirdPlace', locked) : null}
        {v.format === 'double' ? this.toggle('grandFinalReset', locked) : null}
        {v.format === 'swiss' ? this.input('swissRounds', 'number', { min: 0, max: 20, disabled: locked }) : null}
        {v.format === 'round_robin' || v.format === 'swiss' ? this.toggle('allowDraws', locked) : null}
      </fieldset>
    );
  }

  entry(): Mithril.Children {
    const v = this.values;
    const locked = this.started();

    return (
      <fieldset>
        <legend>{t('editor.entry')}</legend>
        <div className="Gauntlet-grid2">
          {v.entryMode === 'members' ? this.select('entryType', ['solo', 'team'], locked) : null}
          {v.entryMode === 'members' && v.entryType === 'team' ? this.input('teamSize', 'number', { min: 0, max: 50, disabled: locked }) : null}
          {this.input('maxEntrants', 'number', { min: 0, max: 256, disabled: locked })}
          {this.select('seeding', ['random', 'manual', 'rating'], locked)}
        </div>
        {v.entryMode === 'members' ? this.toggle('waitlist') : null}
        <div className="Gauntlet-grid2">
          {this.input('startsAt', 'datetime-local')}
          {v.entryMode === 'members' ? this.input('registrationClosesAt', 'datetime-local') : null}
          {v.entryMode === 'members' ? this.input('checkinMinutes', 'number', { min: 0, max: 1440 }) : null}
        </div>
        {v.entryMode === 'members' ? <p className="helpText">{t('editor.checkin_help')}</p> : null}
      </fieldset>
    );
  }

  play(): Mithril.Children {
    const tags = app.store.all<any>('tags').filter((tag: any) => tag.canStartDiscussion?.());

    return (
      <fieldset>
        <legend>{t('editor.play')}</legend>
        <div className="Gauntlet-grid2">
          {this.select('bestOf', ['1', '3', '5', '7', '9'], this.started())}
          {this.values.entryMode === 'members' ? this.select('confirmation', ['agree', 'staff']) : null}
          {tags.length ? (
            <div className="Form-group">
              <label htmlFor="gauntlet-tagId">{t('editor.fields.tagId')}</label>
              <select
                id="gauntlet-tagId"
                className="FormControl"
                onchange={(e: Event) => (this.values.tagId = (e.target as HTMLSelectElement).value)}
              >
                <option value="">{t('editor.no_tag')}</option>
                {tags.map((tag: any) => (
                  <option value={tag.id()} selected={this.values.tagId === tag.id()}>
                    {tag.name()}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
        </div>
        {this.toggle('matchDiscussions')}
        <p className="helpText">{t('editor.discussion_help')}</p>
      </fieldset>
    );
  }

  predictionFields(): Mithril.Children {
    return (
      <fieldset>
        <legend>{t('editor.predictions')}</legend>
        {this.toggle('predictions', this.started())}
        {this.values.predictions ? (
          <div className="Gauntlet-grid2">
            {this.input('predictionsLockAt', 'datetime-local')}
            {this.input('predictionPoints', 'text', { placeholder: '10, 20, 40, 80' })}
          </div>
        ) : null}
        <p className="helpText">{t('editor.predictions_help')}</p>
      </fieldset>
    );
  }

  rewards(): Mithril.Children {
    return (
      <fieldset>
        <legend>{t('editor.rewards')}</legend>
        <div className="Gauntlet-grid2">
          {(app.forum.attribute('tournamentPointsAvailable') ? [0, 1, 2] : []).map((i) => (
            <div className="Form-group">
              <label htmlFor={'gauntlet-reward' + i}>{t('editor.reward_place', { place: i + 1 })}</label>
              <input
                id={'gauntlet-reward' + i}
                className="FormControl"
                type="number"
                min="0"
                value={this.values.rewardPoints[i]}
                oninput={(e: InputEvent) => (this.values.rewardPoints[i] = (e.target as HTMLInputElement).value)}
              />
            </div>
          ))}
          {this.badges.length ? (
            <div className="Form-group">
              <label htmlFor="gauntlet-badge">{t('editor.fields.rewardBadgeId')}</label>
              <select
                id="gauntlet-badge"
                className="FormControl"
                onchange={(e: Event) => (this.values.rewardBadgeId = (e.target as HTMLSelectElement).value)}
              >
                <option value="">{t('editor.no_badge')}</option>
                {this.badges.map((b) => (
                  <option value={b.id} selected={this.values.rewardBadgeId === b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
        </div>
        <p className="helpText">{t('editor.rewards_help')}</p>
      </fieldset>
    );
  }

  submit(e: SubmitEvent) {
    e.preventDefault();
    const v = this.values;
    const data: Values = {
      title: v.title,
      game: v.game,
      description: v.description,
      waitlist: !!v.waitlist,
      startsAt: fromLocal(v.startsAt),
      registrationClosesAt: fromLocal(v.registrationClosesAt),
      checkinMinutes: Number(v.checkinMinutes) || 0,
      confirmation: v.confirmation,
      matchDiscussions: !!v.matchDiscussions,
      tagId: v.tagId || null,
    };

    if (!this.started()) {
      Object.assign(data, {
        format: v.format,
        entryMode: v.entryMode,
        entryType: v.entryMode === 'staff' ? 'solo' : v.entryType,
        teamSize: Number(v.teamSize) || 0,
        maxEntrants: Number(v.maxEntrants) || 0,
        seeding: v.seeding,
        bestOf: Number(v.bestOf) || 1,
        thirdPlace: !!v.thirdPlace,
        grandFinalReset: !!v.grandFinalReset,
        swissRounds: Number(v.swissRounds) || 0,
        allowDraws: !!v.allowDraws,
        predictions: v.format === 'single' && v.entryMode === 'staff' && !!v.predictions,
      });
    }

    if (data.predictions || v.predictions) {
      data.predictionsLockAt = fromLocal(v.predictionsLockAt);
      data.predictionPoints = String(v.predictionPoints)
        .split(/[,\s]+/)
        .filter(Boolean)
        .map(Number);
    }

    if (app.forum.attribute('canRewardTournaments') && app.forum.attribute('tournamentPointsAvailable')) {
      data.rewardPoints = v.rewardPoints.map((p: string) => Number(p) || 0);
    }
    if (app.forum.attribute('canRewardTournaments') && this.badges.length) {
      data.rewardBadgeId = v.rewardBadgeId || null;
    }

    this.saving = true;
    api.save(data, this.id ?? undefined).then(
      ({ data: saved }) => m.route.set(tournamentUrl(saved)),
      () => {
        this.saving = false;
        m.redraw();
      }
    );
  }
}

function toLocal(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromLocal(value: string): string | null {
  return value ? new Date(value).toISOString() : null;
}
