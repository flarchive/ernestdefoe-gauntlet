import app from 'flarum/admin/app';

/*
 * Gauntlet's admin is its permissions and one setting; tournaments are run
 * on the forum, by their organisers.
 */
app.initializers.add('ernestdefoe-gauntlet', () => {
  const t = (key: string) => app.translator.trans(`ernestdefoe-gauntlet.admin.${key}`);

  app.registry
    .for('ernestdefoe-gauntlet')
    .registerSetting({
      setting: 'ernestdefoe-gauntlet.show_nav',
      type: 'boolean',
      label: t('settings.show_nav'),
    })
    .registerPermission({ icon: 'fas fa-trophy', label: t('permissions.view'), permission: 'gauntlet.view', allowGuest: true }, 'view')
    .registerPermission({ icon: 'fas fa-right-to-bracket', label: t('permissions.join'), permission: 'gauntlet.join' }, 'reply')
    .registerPermission({ icon: 'fas fa-list-check', label: t('permissions.predict'), permission: 'gauntlet.predict' }, 'reply')
    .registerPermission({ icon: 'fas fa-sitemap', label: t('permissions.create'), permission: 'gauntlet.create' }, 'start')
    .registerPermission({ icon: 'fas fa-gavel', label: t('permissions.manage'), permission: 'gauntlet.manage' }, 'moderate')
    .registerPermission({ icon: 'fas fa-award', label: t('permissions.reward'), permission: 'gauntlet.reward' }, 'moderate');
});
