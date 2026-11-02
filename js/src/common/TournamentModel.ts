import Model from 'flarum/common/Model';

/** Only what a notification needs to name and link its tournament. */
export default class TournamentModel extends Model {
  title = Model.attribute<string>('title');
  slug = Model.attribute<string>('slug');
}
